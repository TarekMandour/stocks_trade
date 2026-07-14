<?php

namespace App\Services;

use App\Models\AnalysisResult;

/**
 * يحلل كل سهم مرشح للـ Day Trade وينتج:
 * - signal type
 * - opportunity_score, risk_score, confidence_score
 * - reasons[]
 * - metrics[]
 */
class DayTradeAnalyzerService
{
    public function __construct(
        private readonly CandleAnalyzerService $candleAnalyzer,
    ) {}

    /**
     * @param  array<string, mixed>      $stock         بيانات marketwatch
     * @param  array<string, float|int>  $depthMetrics  مخرجات DepthAnalyzerService
     * @param  float                     $initialScore  الـ score الأولي من MarketScoreService
     * @param  array<string, float>      $candles       [timestamp => close_price] من charts API
     * @param  float                     $elapsedFraction  كسر الجلسة المنقضية (0.0 → 1.0)
     * @return array<string, mixed>
     */
    public function analyze(
        array $stock,
        array $depthMetrics,
        float $initialScore,
        array $candles = [],
        float $elapsedFraction = 1.0
    ): array {
        $reasons = [];
        $metrics = [];

        // ----------------------------------------------------------------
        // استخراج القيم الأساسية
        // ----------------------------------------------------------------
        $price       = (float) ($stock['last_trade_price'] ?? 0);
        $open        = (float) ($stock['open_price'] ?? 0);
        $high        = (float) ($stock['high_price'] ?? 0);
        $low         = (float) ($stock['low_price'] ?? 0);
        $prevClose   = (float) ($stock['previous_close'] ?? 0);
        $changePct   = (float) ($stock['last_change_prc'] ?? 0);
        $totalValue  = (float) ($stock['total_value'] ?? 0);
        $totalVolume = (float) ($stock['total_volume'] ?? 0);
        $avg5        = (float) ($stock['avg_5_day'] ?? 1);
        $avg30       = (float) ($stock['avg_30_day'] ?? 1);

        // نُسقّط الحجم والقيمة لنهاية اليوم
        $projectedVolume = $elapsedFraction > 0.05 ? $totalVolume / $elapsedFraction : $totalVolume;
        $projectedValue  = $elapsedFraction > 0.05 ? $totalValue / $elapsedFraction : $totalValue;

        $imbalance    = (float) ($depthMetrics['imbalance_ratio'] ?? 1.0);
        $spreadPct    = (float) ($depthMetrics['spread_pct'] ?? 0);
        $supportLevel = (float) ($depthMetrics['support_level'] ?? 0);
        $depthQuality = (float) ($depthMetrics['depth_quality'] ?? 0);

        $dayRange   = $high - $low;
        $posInRange = $dayRange > 0 ? ($price - $low) / $dayRange : 0.5;
        $volRatio5  = $avg5 > 0 ? $projectedVolume / $avg5 : 0;
        $volRatio30 = $avg30 > 0 ? $projectedVolume / $avg30 : 0;

        // ----------------------------------------------------------------
        // تحليل الشموع الأسبوعية
        // ----------------------------------------------------------------
        $candleMetrics = $this->candleAnalyzer->analyze($candles);
        $candleSupport    = $candleMetrics['support'];
        $candleResistance = $candleMetrics['resistance'];
        $trendSlope       = $candleMetrics['trend_slope'];
        $weeklyChangePct  = $candleMetrics['weekly_change_pct'];

        // نُفضّل دعم الشموع على دعم الـ depth عند توفره
        if ($candleSupport > 0) {
            $supportLevel = max($supportLevel, $candleSupport);
        }

        $metrics['change_pct']         = $changePct;
        $metrics['pos_in_range']       = round($posInRange, 4);
        $metrics['vol_ratio_5d']       = round($volRatio5, 2);
        $metrics['vol_ratio_30d']      = round($volRatio30, 2);
        $metrics['spread_pct']         = $spreadPct;
        $metrics['imbalance']          = $imbalance;
        $metrics['total_value']        = $projectedValue;
        $metrics['depth_quality']      = $depthQuality;
        $metrics['candle_support']     = $candleSupport;
        $metrics['candle_resistance']  = $candleResistance;
        $metrics['trend_slope']        = $trendSlope;
        $metrics['weekly_change_pct']  = $weeklyChangePct;
        $metrics['elapsed_pct']        = round($elapsedFraction * 100, 1);

        // ----------------------------------------------------------------
        // تحديد نوع الإشارة
        // ----------------------------------------------------------------
        $signal = $this->determineSignal(
            $price, $open, $high, $low, $prevClose,
            $changePct, $posInRange, $volRatio5, $imbalance, $spreadPct,
            $trendSlope
        );

        // ----------------------------------------------------------------
        // حساب الـ scores
        // ----------------------------------------------------------------
        [$opportunityScore, $riskScore, $confidenceScore, $scoreReasons]
            = $this->calculateScores(
                $signal, $changePct, $posInRange,
                $volRatio5, $volRatio30, $imbalance,
                $spreadPct, $depthQuality, $projectedValue, $initialScore,
                $trendSlope, $weeklyChangePct, $candleMetrics['candle_count']
            );

        $reasons = array_merge($reasons, $scoreReasons);

        return [
            'signal'            => $signal,
            'opportunity_score' => round($opportunityScore, 2),
            'risk_score'        => round($riskScore, 2),
            'confidence_score'  => round($confidenceScore, 2),
            'reasons'           => $reasons,
            'metrics'           => $metrics,
        ];
    }

    // -------------------------------------------------------------------------
    // Signal Determination
    // -------------------------------------------------------------------------

    private function determineSignal(
        float $price,
        float $open,
        float $high,
        float $low,
        float $prevClose,
        float $changePct,
        float $posInRange,
        float $volRatio5,
        float $imbalance,
        float $spreadPct,
        float $trendSlope
    ): string {
        $maxSpread = (float) config('market_analysis.day_trade.spread_max_pct');

        // Breakout Watch: السعر قريب من الهاي، حجم قوي، زخم إيجابي
        if ($posInRange >= 0.80 && $changePct > 1.0 && $volRatio5 >= 1.2 && $spreadPct < $maxSpread) {
            return AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH;
        }

        // Momentum Buy: تغيير إيجابي قوي، حجم فوق المعدل، ضغط شراء في الـ depth
        if ($changePct >= 2.0 && $volRatio5 >= 1.3 && $imbalance >= 1.2 && $spreadPct < $maxSpread) {
            return AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY;
        }

        // Pullback Buy: السعر في النصف السفلي من نطاق اليوم، حجم فوق المعدل، ضغط شراء
        // نشترط الآن أن يكون الترند الأسبوعي إيجابيًا أو محايداً لتأكيد عدم الانزلاق
        if ($posInRange <= 0.40 && $volRatio5 >= 0.8 && $imbalance >= 1.1 && $changePct > -3.0 && $trendSlope >= -3.0) {
            return AnalysisResult::DAY_SIGNAL_PULLBACK_BUY;
        }

        return AnalysisResult::SIGNAL_WATCH_ONLY;
    }

    // -------------------------------------------------------------------------
    // Score Calculation
    // -------------------------------------------------------------------------

    /**
     * @return array{float, float, float, list<string>}
     */
    private function calculateScores(
        string $signal,
        float $changePct,
        float $posInRange,
        float $volRatio5,
        float $volRatio30,
        float $imbalance,
        float $spreadPct,
        float $depthQuality,
        float $projectedValue,
        float $initialScore,
        float $trendSlope,
        float $weeklyChangePct,
        int $candleCount
    ): array {
        $reasons   = [];
        $maxSpread = (float) config('market_analysis.day_trade.spread_max_pct');
        $highValue = (float) config('market_analysis.day_trade.high_value_threshold');

        // --- Opportunity Score ---
        $oppComponents = [
            'vol_surge'  => min($volRatio5 / 2.0, 1.0) * 30,
            'momentum'   => min(abs($changePct) / 5.0, 1.0) * ($changePct > 0 ? 25 : 10),
            'imbalance'  => min($imbalance / 3.0, 1.0) * 25,
            'liquidity'  => min($projectedValue / $highValue, 1.0) * 20,
        ];

        // مكافأة الترند الأسبوعي الإيجابي
        if ($candleCount > 0 && $trendSlope > 2.0) {
            $oppComponents['weekly_trend'] = min($trendSlope / 10.0, 1.0) * 5;
            $reasons[] = sprintf('ترند أسبوعي صاعد بنسبة %.1f%%.', $trendSlope);
        }

        $opportunityScore = array_sum($oppComponents);

        if ($signal === AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH) {
            $opportunityScore = min($opportunityScore * 1.15, 100);
            $reasons[] = 'السعر يقترب من أعلى مستوى اليوم مع حجم تداول متزايد (Breakout Watch).';
        } elseif ($signal === AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY) {
            $opportunityScore = min($opportunityScore * 1.10, 100);
            $reasons[] = 'زخم إيجابي قوي مع ضغط شراء واضح في عمق السوق (Momentum Buy).';
        } elseif ($signal === AnalysisResult::DAY_SIGNAL_PULLBACK_BUY) {
            $reasons[] = 'السعر في منطقة دعم مع ضغط شراء في عمق السوق (Pullback Buy).';
        }

        if ($volRatio5 >= 1.5) {
            $reasons[] = sprintf('حجم التداول المتوقع أعلى من معدل 5 أيام بنسبة %.0f%%.', ($volRatio5 - 1) * 100);
        }

        if ($imbalance >= 1.5) {
            $reasons[] = sprintf('ضغط شراء قوي في عمق السوق (نسبة %.1f:1).', $imbalance);
        }

        // --- Risk Score ---
        $riskComponents = [
            'spread'     => ($spreadPct / max($maxSpread, 0.01)) * 35,
            'volatility' => min(abs($changePct) / 10.0, 1.0) * 30,
            'depth_lack' => (1.0 - $depthQuality) * 20,
            'low_volume' => $volRatio5 < 0.5 ? 15 : 0,
        ];

        // خصم جزئي إذا كان الترند الأسبوعي سلبياً جداً
        if ($candleCount > 0 && $trendSlope < -5.0) {
            $riskComponents['weekly_downtrend'] = min(abs($trendSlope) / 20.0, 1.0) * 10;
            $reasons[] = sprintf('تحذير: ترند أسبوعي هابط بنسبة %.1f%%.', abs($trendSlope));
        }

        $riskScore = min(array_sum($riskComponents), 100);

        if ($spreadPct > $maxSpread * 0.7) {
            $reasons[] = sprintf('تحذير: spread مرتفع (%.2f%%) يرفع تكلفة الدخول.', $spreadPct);
        }

        // --- Confidence Score ---
        $confComponents = [
            'depth_quality'  => $depthQuality * 30,
            'volume_confirm' => min($volRatio5 / 2.0, 1.0) * 30,
            'signal_bonus'   => $signal !== AnalysisResult::SIGNAL_WATCH_ONLY ? 25 : 0,
            'initial_boost'  => ($initialScore / 100) * 10,
            'candle_bonus'   => $candleCount >= 5 ? 5 : 0,  // مكافأة وجود بيانات شموع كافية
        ];
        $confidenceScore = min(array_sum($confComponents), 100);

        if ($depthQuality < 0.4) {
            $reasons[] = 'بيانات عمق السوق محدودة، تحقق من السيولة قبل الدخول.';
        }

        return [$opportunityScore, $riskScore, $confidenceScore, $reasons];
    }
}
