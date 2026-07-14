<?php

namespace App\Services;

use App\Models\AnalysisResult;

/**
 * يحلل كل سهم مرشح للـ Swing وينتج:
 * - signal type
 * - opportunity_score, risk_score, confidence_score
 * - reasons[]
 * - metrics[]
 */
class SwingAnalyzerService
{
    public function __construct(
        private readonly CandleAnalyzerService $candleAnalyzer,
    ) {}

    /**
     * @param  array<string, mixed>      $stock
     * @param  array<string, float|int>  $depthMetrics
     * @param  float                     $initialScore
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
        // استخراج القيم
        // ----------------------------------------------------------------
        $price     = (float) ($stock['last_trade_price'] ?? 0);
        $open      = (float) ($stock['open_price'] ?? 0);
        $close     = (float) ($stock['close_price'] ?? 0);
        $high52    = (float) ($stock['high_52_week'] ?? 0);
        $low52     = (float) ($stock['low_52_week'] ?? 0);
        $avg30     = (float) ($stock['avg_30_day'] ?? 1);
        $avg90     = (float) ($stock['avg_90_day'] ?? 1);
        $totalVol  = (float) ($stock['total_volume'] ?? 0);
        $totalVal  = (float) ($stock['total_value'] ?? 0);
        $eps       = (float) ($stock['eps'] ?? 0);
        $pe        = (float) ($stock['pe_ratio'] ?? 0);
        $changePct = (float) ($stock['last_change_prc'] ?? 0);

        // نُسقّط الحجم والقيمة لنهاية اليوم
        $projectedVol = $elapsedFraction > 0.05 ? $totalVol / $elapsedFraction : $totalVol;
        $projectedVal = $elapsedFraction > 0.05 ? $totalVal / $elapsedFraction : $totalVal;

        $imbalance    = (float) ($depthMetrics['imbalance_ratio'] ?? 1.0);
        $spreadPct    = (float) ($depthMetrics['spread_pct'] ?? 0);
        $supportLevel = (float) ($depthMetrics['support_level'] ?? 0);
        $depthQuality = (float) ($depthMetrics['depth_quality'] ?? 0);

        $range52    = $high52 - $low52;
        $position52 = $range52 > 0 ? ($price - $low52) / $range52 : 0.5;
        $volRatio30 = $avg30 > 0 ? $projectedVol / $avg30 : 0;
        $volRatio90 = $avg90 > 0 ? $projectedVol / $avg90 : 0;
        $isBullish  = $close >= $open;

        // ----------------------------------------------------------------
        // تحليل الشموع الأسبوعية
        // ----------------------------------------------------------------
        $candleMetrics    = $this->candleAnalyzer->analyze($candles);
        $candleSupport    = $candleMetrics['support'];
        $candleResistance = $candleMetrics['resistance'];
        $trendSlope       = $candleMetrics['trend_slope'];
        $weeklyChangePct  = $candleMetrics['weekly_change_pct'];

        // دعم أقوى: نختار الأعلى بين دعم الـ depth ودعم الشموع
        if ($candleSupport > 0) {
            $supportLevel = max($supportLevel, $candleSupport);
        }

        $metrics['position_52w']     = round($position52 * 100, 1);
        $metrics['vol_ratio_30d']    = round($volRatio30, 2);
        $metrics['vol_ratio_90d']    = round($volRatio90, 2);
        $metrics['change_pct']       = $changePct;
        $metrics['spread_pct']       = $spreadPct;
        $metrics['imbalance']        = $imbalance;
        $metrics['depth_quality']    = $depthQuality;
        $metrics['total_value']      = $projectedVal;
        $metrics['candle_support']   = $candleSupport;
        $metrics['candle_resistance'] = $candleResistance;
        $metrics['trend_slope']      = $trendSlope;
        $metrics['weekly_change_pct'] = $weeklyChangePct;
        $metrics['elapsed_pct']      = round($elapsedFraction * 100, 1);

        if ($eps != 0) {
            $metrics['eps'] = $eps;
        }
        if ($pe != 0) {
            $metrics['pe_ratio'] = $pe;
        }

        // ----------------------------------------------------------------
        // تحديد الإشارة
        // ----------------------------------------------------------------
        $lowerZone = (float) config('market_analysis.swing.week52_lower_zone');
        $upperZone = (float) config('market_analysis.swing.week52_upper_zone');

        $signal = $this->determineSignal(
            $position52, $lowerZone, $upperZone,
            $volRatio30, $volRatio90,
            $imbalance, $isBullish, $spreadPct,
            $trendSlope
        );

        // ----------------------------------------------------------------
        // Scores
        // ----------------------------------------------------------------
        [$opportunityScore, $riskScore, $confidenceScore, $scoreReasons]
            = $this->calculateScores(
                $signal, $position52, $lowerZone, $upperZone,
                $volRatio30, $volRatio90,
                $imbalance, $spreadPct, $depthQuality,
                $projectedVal, $eps, $pe, $initialScore,
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
    // Signal
    // -------------------------------------------------------------------------

    private function determineSignal(
        float $position52,
        float $lowerZone,
        float $upperZone,
        float $volRatio30,
        float $volRatio90,
        float $imbalance,
        bool $isBullish,
        float $spreadPct,
        float $trendSlope
    ): string {
        $maxSpread = (float) config('market_analysis.day_trade.spread_max_pct');

        // Accumulation: في النطاق السفلي + حجم يرتفع + ضغط شراء
        // الآن نشترط أن لا يكون الترند الأسبوعي منهاراً جداً (> -10%)
        if (
            $position52 <= $lowerZone
            && $volRatio30 >= 0.6
            && $imbalance >= 0.9
            && $spreadPct < $maxSpread * 2
            && $trendSlope > -10.0
        ) {
            return AnalysisResult::SWING_SIGNAL_ACCUMULATION;
        }

        // Momentum: في النطاق العلوي + حجم surge + جلسة صاعدة + ترند أسبوعي إيجابي
        if (
            $position52 >= $upperZone
            && $volRatio30 >= 1.2
            && $isBullish
            && $spreadPct < $maxSpread
            && $trendSlope >= 0
        ) {
            return AnalysisResult::SWING_SIGNAL_MOMENTUM;
        }

        return AnalysisResult::SIGNAL_WATCH_ONLY;
    }

    // -------------------------------------------------------------------------
    // Scores
    // -------------------------------------------------------------------------

    /**
     * @return array{float, float, float, list<string>}
     */
    private function calculateScores(
        string $signal,
        float $position52,
        float $lowerZone,
        float $upperZone,
        float $volRatio30,
        float $volRatio90,
        float $imbalance,
        float $spreadPct,
        float $depthQuality,
        float $projectedVal,
        float $eps,
        float $pe,
        float $initialScore,
        float $trendSlope,
        float $weeklyChangePct,
        int $candleCount
    ): array {
        $reasons   = [];
        $maxSpread = (float) config('market_analysis.day_trade.spread_max_pct');

        // --- Opportunity Score ---
        $oppComponents = [];

        if ($signal === AnalysisResult::SWING_SIGNAL_ACCUMULATION) {
            $distanceFromLow             = max(0, $lowerZone - $position52) / $lowerZone;
            $oppComponents['proximity_to_low'] = $distanceFromLow * 35;
            $oppComponents['volume_confirm']   = min($volRatio30 / 1.5, 1.0) * 25;
            $oppComponents['buy_pressure']     = min($imbalance / 2.0, 1.0) * 25;
            $oppComponents['liquidity']        = min($projectedVal / 2_000_000, 1.0) * 10;
            $reasons[] = sprintf('السهم في منطقة تراكم (%.1f%% من النطاق السنوي).', $position52 * 100);

            // مكافأة: الترند الأسبوعي يتعافى
            if ($candleCount > 0 && $trendSlope >= -2.0) {
                $oppComponents['trend_recovery'] = 5.0;
                $reasons[] = 'الترند الأسبوعي يُظهر تعافياً في منطقة التراكم.';
            }
        } elseif ($signal === AnalysisResult::SWING_SIGNAL_MOMENTUM) {
            $oppComponents['upper_zone_bonus'] = 25.0;
            $oppComponents['volume_surge']     = min($volRatio30 / 2.0, 1.0) * 30;
            $oppComponents['buy_pressure']     = min($imbalance / 2.0, 1.0) * 25;
            $oppComponents['liquidity']        = min($projectedVal / 2_000_000, 1.0) * 10;
            $reasons[] = sprintf('السهم في منطقة زخم (%.1f%% من النطاق السنوي).', $position52 * 100);

            // مكافأة: الترند الأسبوعي قوي
            if ($candleCount > 0 && $trendSlope >= 3.0) {
                $oppComponents['weekly_momentum'] = min($trendSlope / 15.0, 1.0) * 10;
                $reasons[] = sprintf('زخم أسبوعي قوي بنسبة %.1f%%.', $trendSlope);
            }
        } else {
            $oppComponents['base'] = $initialScore * 0.3;
        }

        // مساعدة EPS/PE
        if ($eps > 0 && $pe > 0 && $pe < 20) {
            $oppComponents['fundamentals'] = 5.0;
            $reasons[] = sprintf('مؤشرات أساسية مواتية: EPS=%.2f, P/E=%.1f.', $eps, $pe);
        }

        $opportunityScore = min(array_sum($oppComponents), 100);

        if ($volRatio90 >= 1.2) {
            $reasons[] = sprintf('الحجم المتوقع أعلى من معدل 90 يومًا بنسبة %.0f%%.', ($volRatio90 - 1) * 100);
        }

        if ($imbalance >= 1.3) {
            $reasons[] = sprintf('ضغط شراء في عمق السوق (نسبة %.1f:1).', $imbalance);
        }

        // --- Risk Score ---
        $riskComponents = [
            'spread'        => ($spreadPct / max($maxSpread * 2, 0.01)) * 35,
            'depth_lack'    => (1.0 - $depthQuality) * 25,
            'position_risk' => ($position52 > 0.85 ? ($position52 - 0.85) / 0.15 * 20 : 0),
            'low_liquidity' => ($projectedVal < 500_000 ? 20 : 0),
        ];

        // مخاطرة إضافية عند الترند الأسبوعي السلبي
        if ($candleCount > 0 && $trendSlope < -5.0) {
            $riskComponents['downtrend_risk'] = min(abs($trendSlope) / 20.0, 1.0) * 15;
            $reasons[] = sprintf('تحذير: ترند أسبوعي هابط بنسبة %.1f%%.', abs($trendSlope));
        }

        $riskScore = min(array_sum($riskComponents), 100);

        if ($spreadPct > $maxSpread) {
            $reasons[] = sprintf('تحذير: spread مرتفع (%.2f%%) قد يؤثر على جودة التنفيذ.', $spreadPct);
        }

        if ($position52 > 0.88) {
            $reasons[] = 'تحذير: السعر قريب من أعلى مستوى سنوي، مخاطر التراجع أعلى.';
        }

        // --- Confidence Score ---
        $confComponents = [
            'depth_quality'  => $depthQuality * 25,
            'volume_confirm' => min($volRatio30 / 2.0, 1.0) * 25,
            'signal_bonus'   => $signal !== AnalysisResult::SIGNAL_WATCH_ONLY ? 30 : 0,
            'initial_boost'  => ($initialScore / 100) * 10,
            'candle_bonus'   => $candleCount >= 5 ? 10 : 0,  // ثقة أعلى عند توفر بيانات شموع كافية
        ];
        $confidenceScore = min(array_sum($confComponents), 100);

        return [$opportunityScore, $riskScore, $confidenceScore, $reasons];
    }
}
