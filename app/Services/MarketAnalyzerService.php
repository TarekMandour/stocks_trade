<?php

namespace App\Services;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * يُنسّق تسلسل التحليل الكامل:
 * 1. جلب marketwatch
 * 2. تصفية الأسهم المؤهلة
 * 3. حساب الـ score الأولي
 * 4. اختيار المرشحين
 * 5. جلب market depth للمرشحين
 * 6. جلب بيانات الشموع (charts) للمرشحين
 * 7. تحليل Day Trade
 * 8. تحليل Swing
 * 9. حفظ النتائج
 */
class MarketAnalyzerService
{
    /** ساعة فتح السوق المصري (CLT = UTC+3) */
    private const MARKET_OPEN_HOUR = 10;

    private const MARKET_OPEN_MINUTE = 0;

    /** ساعة إغلاق السوق المصري */
    private const MARKET_CLOSE_HOUR = 14;

    private const MARKET_CLOSE_MINUTE = 30;

    /** مدة الجلسة بالدقائق = 270 */
    private const SESSION_DURATION_MIN = 270;

    public function __construct(
        private readonly ThndrApiService $thndrApi,
        private readonly MarketScoreService $scoreService,
        private readonly DepthAnalyzerService $depthAnalyzer,
        private readonly DayTradeAnalyzerService $dayTradeAnalyzer,
        private readonly SwingAnalyzerService $swingAnalyzer,
        private readonly SignalCalculatorService $signalCalculator,
        private readonly AnalysisPersistenceService $persistence,
    ) {}

    /**
     * نقطة الدخول الرئيسية.
     */
    public function run(string $token): AnalysisRun
    {
        $today = today()->toDateString();

        Log::info('[Analyzer] Starting analysis run', ['date' => $today]);

        $run = $this->persistence->upsertRun($today);

        try {
            // ----------------------------------------------------------------
            // 1. جلب marketwatch
            // ----------------------------------------------------------------
            $marketwatch = $this->thndrApi->getMarketWatch($token);

            Log::info('[Analyzer] Marketwatch fetched', ['total' => count($marketwatch)]);

            // ----------------------------------------------------------------
            // 2. حساب الكسر المنقضي من الجلسة
            // ----------------------------------------------------------------
            $elapsedFraction = $this->calcElapsedFraction();

            // ----------------------------------------------------------------
            // 3. تصفية الأسهم المؤهلة
            // ----------------------------------------------------------------
            $eligible = $this->filterEligible($marketwatch, $elapsedFraction);

            Log::info('[Analyzer] Eligible stocks', ['count' => count($eligible)]);

            $this->persistence->updateRunStats($run, count($marketwatch), count($eligible));

            // ----------------------------------------------------------------
            // 4. حساب الـ score الأولي لكل نوع
            // ----------------------------------------------------------------
            $dayScored = $this->scoreAndSort($eligible, 'day', $elapsedFraction);
            $swingScored = $this->scoreAndSort($eligible, 'swing', $elapsedFraction);

            // ----------------------------------------------------------------
            // 5. اختيار المرشحين لجلب الـ depth
            // ----------------------------------------------------------------
            $dayTopN = (int) config('market_analysis.day_trade.candidates_to_fetch_depth');
            $swingTopN = (int) config('market_analysis.swing.candidates_to_fetch_depth');

            $dayCandidates = array_slice($dayScored, 0, $dayTopN);
            $swingCandidates = array_slice($swingScored, 0, $swingTopN);

            // دمج بدون تكرار (نجلب الـ depth مرة واحدة لكل asset_id)
            $allCandidates = $this->mergeUnique($dayCandidates, $swingCandidates);

            Log::info('[Analyzer] Fetching depth for candidates', ['count' => count($allCandidates)]);

            // ----------------------------------------------------------------
            // 6. جلب market depth
            // ----------------------------------------------------------------
            $depthMap = $this->fetchDepthMap($token, $allCandidates);

            // ----------------------------------------------------------------
            // 7. جلب بيانات الشموع (charts)
            // ----------------------------------------------------------------
            $chartMap = $this->fetchChartMap($token, $allCandidates);

            // ----------------------------------------------------------------
            // 8. تحليل Day Trade
            // ----------------------------------------------------------------
            $minDayScore = (float) config('market_analysis.day_trade.min_score_to_shortlist');
            $topDayN = (int) config('market_analysis.day_trade.top_n');

            $dayResults = $this->analyzeCandidates(
                $dayCandidates, $depthMap, $chartMap, 'day', $minDayScore, $topDayN, $elapsedFraction
            );

            // ----------------------------------------------------------------
            // 9. تحليل Swing
            // ----------------------------------------------------------------
            $minSwingScore = (float) config('market_analysis.swing.min_score_to_shortlist');
            $topSwingN = (int) config('market_analysis.swing.top_n');

            $swingResults = $this->analyzeCandidates(
                $swingCandidates, $depthMap, $chartMap, 'swing', $minSwingScore, $topSwingN, $elapsedFraction
            );

            Log::info('[Analyzer] Analysis complete', [
                'day_results' => count($dayResults),
                'swing_results' => count($swingResults),
            ]);

            // ----------------------------------------------------------------
            // 10. حفظ النتائج
            // ----------------------------------------------------------------
            $this->persistence->saveResults(
                $run,
                $dayResults,
                $swingResults,
                config('market_analysis')
            );

        } catch (Throwable $e) {
            Log::error('[Analyzer] Analysis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->persistence->markFailed($run, $e->getMessage());
            throw $e;
        }

        return $run->fresh(['dayTradeResults', 'swingResults']);
    }

    // -------------------------------------------------------------------------
    // Session Time Helpers
    // -------------------------------------------------------------------------

    /**
     * حساب كسر الوقت المنقضي من الجلسة (0.0 → 1.0).
     * 0.0 = بداية الجلسة، 1.0 = نهاية الجلسة أو ما بعدها.
     */
    public function calcElapsedFraction(?Carbon $now = null): float
    {
        $now = $now ?? Carbon::now('Africa/Cairo');

        $open = Carbon::today('Africa/Cairo')
            ->setHour(self::MARKET_OPEN_HOUR)
            ->setMinute(self::MARKET_OPEN_MINUTE)
            ->setSecond(0);

        $close = Carbon::today('Africa/Cairo')
            ->setHour(self::MARKET_CLOSE_HOUR)
            ->setMinute(self::MARKET_CLOSE_MINUTE)
            ->setSecond(0);

        if ($now->lessThanOrEqualTo($open)) {
            return 0.0;
        }

        if ($now->greaterThanOrEqualTo($close)) {
            return 1.0;
        }

        $elapsed = $open->diffInMinutes($now);

        return min($elapsed / self::SESSION_DURATION_MIN, 1.0);
    }

    // -------------------------------------------------------------------------
    // Eligibility Filter
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $stocks
     * @return list<array<string, mixed>>
     */
    private function filterEligible(array $stocks, float $elapsedFraction): array
    {
        $cfg = config('market_analysis.eligibility');

        $minElapsedMin = (int) ($cfg['session_min_elapsed_min'] ?? 30);
        $elapsedMinutes = $elapsedFraction * self::SESSION_DURATION_MIN;
        $limitMarginPct = (float) ($cfg['allowed_limit_margin_pct'] ?? 0.5);

        return array_values(array_filter($stocks, function (array $stock) use ($cfg, $elapsedMinutes, $elapsedFraction, $limitMarginPct, $minElapsedMin): bool {
            // الشرط الأساسي: الحالة النشطة
            if (($stock['symbol_state'] ?? '') !== $cfg['active_state']) {
                return false;
            }

            // ----------------------------------------------------------------
            // تصفية بالقيمة / الحجم / الصفقات
            // إذا لم تمر الدقائق الكافية، نستند إلى المتوسطات بدلاً من الأرقام الفعلية
            // ----------------------------------------------------------------
            if ($elapsedMinutes >= $minElapsedMin && $elapsedFraction > 0) {
                // نُسقط القيم الفعلية ونقيسها باستناد الحجم المُتوقَّع لنهاية اليوم
                $projectedVolume = $elapsedFraction > 0
                    ? (float) ($stock['total_volume'] ?? 0) / $elapsedFraction
                    : (float) ($stock['total_volume'] ?? 0);

                $projectedValue = $elapsedFraction > 0
                    ? (float) ($stock['total_value'] ?? 0) / $elapsedFraction
                    : (float) ($stock['total_value'] ?? 0);

                $projectedTrades = $elapsedFraction > 0
                    ? (int) ceil(($stock['total_trades'] ?? 0) / $elapsedFraction)
                    : (int) ($stock['total_trades'] ?? 0);
            } else {
                // في بداية الجلسة: نستند للمتوسطات التاريخية كمعيار بديل
                $projectedVolume = (float) ($stock['avg_5_day'] ?? 0);
                $projectedValue = $projectedVolume * (float) ($stock['last_trade_price'] ?? 0);
                $projectedTrades = $cfg['min_total_trades'];  // نمرر الشرط إذا كان avg موجودًا
            }

            if ($projectedValue < $cfg['min_total_value']) {
                return false;
            }

            if ($projectedVolume < $cfg['min_total_volume']) {
                return false;
            }

            if ($projectedTrades < $cfg['min_total_trades']) {
                return false;
            }

            // ----------------------------------------------------------------
            // تصفية الأسهم المحدودة حركة (locked limit) باستخدام الحدود الفعلية للسهم
            // ----------------------------------------------------------------
            $price = (float) ($stock['last_trade_price'] ?? 0);
            $highPriceLimit = (float) ($stock['high_price_limit'] ?? 0);
            $lowPriceLimit = (float) ($stock['low_price_limit'] ?? 0);

            if ($price > 0 && $highPriceLimit > 0) {
                // إذا كان السعر في آخر limitMarginPct% من الحد الأعلى → محدود ارتفاعاً
                $upperThreshold = $highPriceLimit * (1 - $limitMarginPct / 100);
                if ($price >= $upperThreshold) {
                    return false;
                }
            }

            if ($price > 0 && $lowPriceLimit > 0) {
                // إذا كان السعر في أول limitMarginPct% من الحد الأدنى → محدود انخفاضاً
                $lowerThreshold = $lowPriceLimit * (1 + $limitMarginPct / 100);
                if ($price <= $lowerThreshold) {
                    return false;
                }
            }

            return true;
        }));
    }

    // -------------------------------------------------------------------------
    // Scoring & Sorting
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $stocks
     * @return list<array{stock: array<string, mixed>, initial_score: float}>
     */
    private function scoreAndSort(array $stocks, string $type, float $elapsedFraction): array
    {
        $scored = [];

        foreach ($stocks as $stock) {
            $score = $type === 'day'
                ? $this->scoreService->calculateDayTradeInitialScore($stock, $elapsedFraction)
                : $this->scoreService->calculateSwingInitialScore($stock, $elapsedFraction);

            $scored[] = ['stock' => $stock, 'initial_score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['initial_score'] <=> $a['initial_score']);

        return $scored;
    }

    // -------------------------------------------------------------------------
    // Depth Fetching
    // -------------------------------------------------------------------------

    /**
     * @param  list<array{stock: array<string, mixed>, initial_score: float}>  $candidates
     * @return array<string, array<string, mixed>> keyed by asset_id
     */
    private function fetchDepthMap(string $token, array $candidates): array
    {
        $depthMap = [];

        foreach ($candidates as $candidate) {
            $assetId = $candidate['stock']['asset_id'] ?? null;

            if (! $assetId || isset($depthMap[$assetId])) {
                continue;
            }

            $raw = $this->thndrApi->getMarketDepth($token, $assetId);
            $depthMap[$assetId] = [
                'raw' => $raw,
                'metrics' => $this->depthAnalyzer->analyze($raw),
            ];
        }

        return $depthMap;
    }

    // -------------------------------------------------------------------------
    // Chart Fetching
    // -------------------------------------------------------------------------

    /**
     * جلب بيانات الشموع لمجموعة المرشحين دفعةً واحدة.
     *
     * @param  list<array{stock: array<string, mixed>, initial_score: float}>  $candidates
     * @return array<string, array<string, float>> keyed by asset_id → [timestamp => close_price]
     */
    private function fetchChartMap(string $token, array $candidates): array
    {
        $assetIds = array_values(array_unique(array_filter(array_map(
            fn ($c) => $c['stock']['asset_id'] ?? null,
            $candidates
        ))));

        if (empty($assetIds)) {
            return [];
        }

        // نُقسّم إلى دفعات من 20 سهماً لتجنب طول الـ URL
        $chartMap = [];
        foreach (array_chunk($assetIds, 20) as $batch) {
            $batchData = $this->thndrApi->getCharts($token, $batch);
            $chartMap = array_merge($chartMap, $batchData);
        }

        return $chartMap;
    }

    // -------------------------------------------------------------------------
    // Analysis
    // -------------------------------------------------------------------------

    /**
     * @param  list<array{stock: array<string, mixed>, initial_score: float}>  $candidates
     * @param  array<string, array{raw: array<string, mixed>, metrics: array<string, float|int>}>  $depthMap
     * @param  array<string, array<string, float>>  $chartMap
     * @return list<array<string, mixed>>
     */
    private function analyzeCandidates(
        array $candidates,
        array $depthMap,
        array $chartMap,
        string $type,
        float $minScore,
        int $topN,
        float $elapsedFraction
    ): array {
        $results = [];

        foreach ($candidates as $candidate) {
            $stock = $candidate['stock'];
            $initialScore = $candidate['initial_score'];
            $assetId = $stock['asset_id'] ?? '';

            $depthEntry = $depthMap[$assetId] ?? ['raw' => [], 'metrics' => []];
            $depthMetrics = $depthEntry['metrics'];

            // بيانات الشموع لهذا السهم (timestamp => price)
            $candles = $chartMap[$assetId] ?? [];

            if ($type === 'day') {
                $scores = $this->dayTradeAnalyzer->analyze($stock, $depthMetrics, $initialScore, $candles, $elapsedFraction);
            } else {
                $scores = $this->swingAnalyzer->analyze($stock, $depthMetrics, $initialScore, $candles, $elapsedFraction);
            }

            // نتجاهل watch_only إلا إذا كان المجموع كافيًا
            if ($scores['opportunity_score'] < $minScore && $scores['signal'] === 'watch_only') {
                continue;
            }

            $signals = $this->signalCalculator->calculate($scores['signal'], $stock, $depthMetrics, $candles);

            if (
                $scores['signal'] !== AnalysisResult::SIGNAL_WATCH_ONLY
                && $signals['entry_price'] === null
                && $signals['entry_from'] === null
            ) {
                continue;
            }

            $results[] = [
                'stock' => $stock,
                'scores' => $scores,
                'signals' => $signals,
                'depth' => $depthEntry['raw'] ?? [],
            ];
        }

        // ترتيب بـ opportunity_score ثم confidence_score
        usort($results, function (array $a, array $b): int {
            $oppDiff = $b['scores']['opportunity_score'] <=> $a['scores']['opportunity_score'];
            if ($oppDiff !== 0) {
                return $oppDiff;
            }

            return $b['scores']['confidence_score'] <=> $a['scores']['confidence_score'];
        });

        return array_slice($results, 0, $topN);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * دمج قائمتين بدون تكرار بناءً على asset_id.
     *
     * @param  list<array{stock: array<string, mixed>, initial_score: float}>  $a
     * @param  list<array{stock: array<string, mixed>, initial_score: float}>  $b
     * @return list<array{stock: array<string, mixed>, initial_score: float}>
     */
    private function mergeUnique(array $a, array $b): array
    {
        $seen = [];
        $merged = [];

        foreach (array_merge($a, $b) as $item) {
            $id = $item['stock']['asset_id'] ?? $item['stock']['symbol_code'] ?? '';
            if ($id && ! isset($seen[$id])) {
                $seen[$id] = true;
                $merged[] = $item;
            }
        }

        return $merged;
    }
}
