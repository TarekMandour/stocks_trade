<?php

namespace App\Services;

/**
 * مساعد لتحليل بيانات الشموع (candles) واستخراج
 * مستويات الدعم والمقاومة والزخم من أسعار الإغلاق.
 */
class CandleAnalyzerService
{
    /**
     * استخراج metrics من بيانات الشموع.
     *
     * @param  array<string, float>  $candles  [timestamp => close_price] مرتبة زمنياً
     * @return array{
     *   support: float,
     *   resistance: float,
     *   trend_slope: float,
     *   weekly_change_pct: float,
     *   candle_count: int,
     *   price_range_pct: float
     * }
     */
    public function analyze(array $candles): array
    {
        if (empty($candles)) {
            return $this->empty();
        }

        // ترتيب تصاعدي زمنياً
        ksort($candles);
        $prices = array_values($candles);

        $count = count($prices);
        $first = $prices[0];
        $last  = $prices[$count - 1];

        // مستوى الدعم: أدنى سعر في الفترة
        $support = min($prices);

        // مستوى المقاومة: أعلى سعر في الفترة
        $resistance = max($prices);

        // نطاق السعر كنسبة مئوية
        $priceRangePct = $first > 0 ? (($resistance - $support) / $first) * 100 : 0.0;

        // الميل الخطي البسيط (trend slope): التغير من أول إلى آخر شمعة
        $trendSlope = $first > 0 ? (($last - $first) / $first) * 100 : 0.0;

        // التغير الأسبوعي المئوي (نهاية الفترة مقارنةً بالبداية)
        $weeklyChangePct = $trendSlope;

        // نُحسّن الدعم: نأخذ أقوى مستوى من حيث تكرار الارتداد
        $support    = $this->findStrongestLevel($prices, 'support');
        $resistance = $this->findStrongestLevel($prices, 'resistance');

        return [
            'support'           => round($support, 4),
            'resistance'        => round($resistance, 4),
            'trend_slope'       => round($trendSlope, 4),
            'weekly_change_pct' => round($weeklyChangePct, 4),
            'candle_count'      => $count,
            'price_range_pct'   => round($priceRangePct, 4),
        ];
    }

    /**
     * إيجاد أقوى مستوى دعم أو مقاومة بناءً على تكرار الارتداد عند هذا السعر.
     *
     * نقسّم النطاق السعري إلى bins ونختار الـ bin الأكثر تكراراً.
     *
     * @param  list<float> $prices
     */
    private function findStrongestLevel(array $prices, string $type): float
    {
        if (count($prices) < 3) {
            return $type === 'support' ? min($prices) : max($prices);
        }

        $minP = min($prices);
        $maxP = max($prices);

        if ($minP >= $maxP) {
            return $type === 'support' ? $minP : $maxP;
        }

        $bins     = 10;
        $binWidth = ($maxP - $minP) / $bins;
        $counts   = array_fill(0, $bins, 0);

        foreach ($prices as $price) {
            $idx = (int) min(floor(($price - $minP) / $binWidth), $bins - 1);
            $counts[$idx]++;
        }

        if ($type === 'support') {
            // الـ bin الأكثر تكراراً في النصف السفلي
            $halfCounts = array_slice($counts, 0, (int) ceil($bins / 2));
            $maxIdx     = array_search(max($halfCounts), $halfCounts);
            $maxIdx     = $maxIdx !== false ? (int) $maxIdx : 0;

            return round($minP + ($maxIdx + 0.5) * $binWidth, 4);
        }

        // resistance: الـ bin الأكثر تكراراً في النصف العلوي
        $halfCounts = array_slice($counts, (int) floor($bins / 2));
        $maxIdx     = array_search(max($halfCounts), $halfCounts);
        $maxIdx     = $maxIdx !== false ? (int) $maxIdx + (int) floor($bins / 2) : $bins - 1;

        return round($minP + ($maxIdx + 0.5) * $binWidth, 4);
    }

    /** @return array{support: float, resistance: float, trend_slope: float, weekly_change_pct: float, candle_count: int, price_range_pct: float} */
    public function empty(): array
    {
        return [
            'support'           => 0.0,
            'resistance'        => 0.0,
            'trend_slope'       => 0.0,
            'weekly_change_pct' => 0.0,
            'candle_count'      => 0,
            'price_range_pct'   => 0.0,
        ];
    }
}
