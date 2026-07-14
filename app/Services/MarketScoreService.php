<?php

namespace App\Services;

/**
 * يحسب الـ score الأولي لكل سهم قبل جلب market depth.
 * الهدف هو الفلترة السريعة للمرشحين الأكثر جدارة.
 */
class MarketScoreService
{
    /** @param array<string, mixed> $stock */
    public function calculateDayTradeInitialScore(array $stock, float $elapsedFraction = 1.0): float
    {
        $weights = config('market_analysis.day_trade.weights');

        $liquidityScore   = $this->dayLiquidityScore($stock, $elapsedFraction);
        $momentumScore    = $this->dayMomentumScore($stock);
        $spreadScore      = $this->daySpreadScore($stock);
        $volumeRatioScore = $this->dayVolumeRatioScore($stock, $elapsedFraction);

        // depth_imbalance يُحسب لاحقًا، نستخدم 0 هنا ونعوّضه من باقي الأوزان
        $baseWeightTotal = $weights['liquidity'] + $weights['momentum'] + $weights['spread'] + $weights['volume_ratio'];
        $adjustedScore = (
            ($liquidityScore   * $weights['liquidity'])
            + ($momentumScore  * $weights['momentum'])
            + ($spreadScore    * $weights['spread'])
            + ($volumeRatioScore * $weights['volume_ratio'])
        ) / $baseWeightTotal;

        return round($adjustedScore * 100, 2);
    }

    /** @param array<string, mixed> $stock */
    public function calculateSwingInitialScore(array $stock, float $elapsedFraction = 1.0): float
    {
        $weights = config('market_analysis.swing.weights');

        $liquidityQuality = $this->swingLiquidityQualityScore($stock, $elapsedFraction);
        $volumeVsAvg      = $this->swingVolumeVsAvgScore($stock, $elapsedFraction);
        $week52Position   = $this->swing52WeekPositionScore($stock);
        $sessionStrength  = $this->swingSessionStrengthScore($stock);
        $spreadRisk       = $this->swingSpreadRiskScore($stock);

        $score = (
            ($liquidityQuality * $weights['liquidity_quality'])
            + ($volumeVsAvg    * $weights['volume_vs_avg'])
            + ($week52Position * $weights['week52_position'])
            + ($sessionStrength * $weights['session_strength'])
            + ($spreadRisk      * $weights['spread_risk'])
        );

        return round($score * 100, 2);
    }

    // -------------------------------------------------------------------------
    // Day Trade Sub-scores (0.0 – 1.0)
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $stock */
    private function dayLiquidityScore(array $stock, float $elapsedFraction): float
    {
        $totalValue    = (float) ($stock['total_value'] ?? 0);
        $highThreshold = (float) config('market_analysis.day_trade.high_value_threshold');

        // نُسقّط القيمة الفعلية إلى نهاية اليوم
        $projectedValue = $elapsedFraction > 0.05
            ? $totalValue / $elapsedFraction
            : (float) ($stock['avg_5_day'] ?? 0) * (float) ($stock['last_trade_price'] ?? 0);

        return min($projectedValue / max($highThreshold, 1), 1.0);
    }

    /** @param array<string, mixed> $stock */
    private function dayMomentumScore(array $stock): float
    {
        $changePct = (float) ($stock['last_change_prc'] ?? 0);
        $high      = (float) ($stock['high_price'] ?? 0);
        $low       = (float) ($stock['low_price'] ?? 0);
        $last      = (float) ($stock['last_trade_price'] ?? 0);

        // موقع السعر داخل نطاق اليوم (0=عند اللو, 1=عند الهاي)
        $dayRange        = $high - $low;
        $positionInRange = $dayRange > 0 ? ($last - $low) / $dayRange : 0.5;

        // تحويل نسبة التغيير إلى score (0-1) بحد أقصى عند 5%
        $changeFactor = min(abs($changePct) / 5.0, 1.0);
        // نعطي وزنًا أقل للتغيير السلبي القوي
        if ($changePct < 0) {
            $changeFactor *= 0.4;
        }

        return ($changeFactor * 0.6) + ($positionInRange * 0.4);
    }

    /** @param array<string, mixed> $stock */
    private function daySpreadScore(array $stock): float
    {
        $bid    = (float) ($stock['bid_price'] ?? 0);
        $ask    = (float) ($stock['ask_price'] ?? 0);
        $maxPct = (float) config('market_analysis.day_trade.spread_max_pct');

        if ($bid <= 0 || $ask <= 0) {
            return 0.5;
        }

        $spreadPct = (($ask - $bid) / $bid) * 100;

        // كلما كان الـ spread أقل كلما كان الـ score أعلى
        if ($spreadPct >= $maxPct) {
            return 0.0;
        }

        return 1.0 - ($spreadPct / $maxPct);
    }

    /** @param array<string, mixed> $stock */
    private function dayVolumeRatioScore(array $stock, float $elapsedFraction): float
    {
        $totalVolume = (float) ($stock['total_volume'] ?? 0);
        $avg5        = (float) ($stock['avg_5_day'] ?? 0);
        $surgeRatio  = (float) config('market_analysis.day_trade.volume_surge_ratio');

        if ($avg5 <= 0) {
            return 0.3;
        }

        // نُسقّط الحجم الفعلي لنهاية اليوم
        $projectedVolume = $elapsedFraction > 0.05 ? $totalVolume / $elapsedFraction : $totalVolume;

        $ratio = $projectedVolume / $avg5;

        // عند surge_ratio يحصل على 1.0، بشكل خطي
        return min($ratio / $surgeRatio, 1.0);
    }

    // -------------------------------------------------------------------------
    // Swing Sub-scores (0.0 – 1.0)
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $stock */
    private function swingLiquidityQualityScore(array $stock, float $elapsedFraction): float
    {
        $totalValue = (float) ($stock['total_value'] ?? 0);
        $avg30      = (float) ($stock['avg_30_day'] ?? 0);

        $projectedValue = $elapsedFraction > 0.05
            ? $totalValue / $elapsedFraction
            : $totalValue;

        // نبحث عن سيولة ثابتة وليس فقط قيمة يومية عالية
        $consistencyRatio = $avg30 > 0 ? min($projectedValue / $avg30, 2.0) / 2.0 : 0.0;
        $volumeQuality    = min($projectedValue / 1_000_000, 1.0); // حد عند مليون جنيه

        return ($consistencyRatio * 0.5) + ($volumeQuality * 0.5);
    }

    /** @param array<string, mixed> $stock */
    private function swingVolumeVsAvgScore(array $stock, float $elapsedFraction): float
    {
        $totalVolume = (float) ($stock['total_volume'] ?? 0);
        $avg30       = (float) ($stock['avg_30_day'] ?? 0);
        $avg90       = (float) ($stock['avg_90_day'] ?? 0);
        $minRatio    = (float) config('market_analysis.swing.volume_vs_avg30_min_ratio');

        if ($avg30 <= 0) {
            return 0.2;
        }

        // نُسقّط الحجم الفعلي لنهاية اليوم
        $projectedVolume = $elapsedFraction > 0.05 ? $totalVolume / $elapsedFraction : $totalVolume;

        $ratio30 = $projectedVolume / $avg30;

        // إذا لم يبلغ الحد الأدنى فالـ score منخفض
        if ($ratio30 < $minRatio) {
            return $ratio30 * 0.3;
        }

        $score30 = min($ratio30 / 2.0, 1.0);

        if ($avg90 > 0) {
            $ratio90 = $projectedVolume / $avg90;
            $score90 = min($ratio90 / 1.5, 1.0);

            return ($score30 * 0.6) + ($score90 * 0.4);
        }

        return $score30;
    }

    /** @param array<string, mixed> $stock */
    private function swing52WeekPositionScore(array $stock): float
    {
        $high52    = (float) ($stock['high_52_week'] ?? 0);
        $low52     = (float) ($stock['low_52_week'] ?? 0);
        $lastPrice = (float) ($stock['last_trade_price'] ?? 0);
        $lowerZone = (float) config('market_analysis.swing.week52_lower_zone');
        $upperZone = (float) config('market_analysis.swing.week52_upper_zone');

        $range = $high52 - $low52;

        if ($range <= 0 || $lastPrice <= 0) {
            return 0.5;
        }

        $position = ($lastPrice - $low52) / $range; // 0=عند اللو, 1=عند الهاي

        if ($position <= $lowerZone) {
            // منطقة تراكم: أعلى score كلما كان أقرب للـ low
            return 0.8 + ((1.0 - ($position / $lowerZone)) * 0.2);
        }

        if ($position >= $upperZone) {
            // منطقة زخم: score جيد لكن أقل من التراكم
            return 0.5 + (($position - $upperZone) / (1.0 - $upperZone)) * 0.3;
        }

        // المنطقة الوسطى: score متوسط
        return 0.4;
    }

    /** @param array<string, mixed> $stock */
    private function swingSessionStrengthScore(array $stock): float
    {
        $open      = (float) ($stock['open_price'] ?? 0);
        $close     = (float) ($stock['close_price'] ?? 0);
        $high      = (float) ($stock['high_price'] ?? 0);
        $low       = (float) ($stock['low_price'] ?? 0);
        $prevClose = (float) ($stock['previous_close'] ?? 0);

        if ($open <= 0 || $prevClose <= 0) {
            return 0.5;
        }

        $dayRange  = $high - $low;
        $bodySize  = abs($close - $open);
        $bodyRatio = $dayRange > 0 ? $bodySize / $dayRange : 0;

        // جلسة صاعدة: close > open ومعظم اليوم صاعد
        $isBullish = $close >= $open;
        $gapUp     = $open > $prevClose;

        $strengthFactor = $bodyRatio * ($isBullish ? 1.0 : 0.3);

        if ($gapUp && $isBullish) {
            $strengthFactor = min($strengthFactor * 1.2, 1.0);
        }

        return $strengthFactor;
    }

    /** @param array<string, mixed> $stock */
    private function swingSpreadRiskScore(array $stock): float
    {
        // نعكس منطق الـ spread: spread أقل = risk أقل = score أعلى
        return $this->daySpreadScore($stock);
    }
}
