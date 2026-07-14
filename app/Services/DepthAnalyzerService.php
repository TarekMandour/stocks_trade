<?php

namespace App\Services;

/**
 * يحلل بيانات عمق السوق (market depth) ويُنتج metrics مفيدة.
 */
class DepthAnalyzerService
{
    /**
     * @param  array<string, mixed> $depth
     * @return array<string, float|int>
     */
    public function analyze(array $depth): array
    {
        $bids = $depth['bids_per_price'] ?? [];
        $asks = $depth['asks_per_price'] ?? [];
        $totals = $depth['total_bids_and_asks'] ?? [];

        $totalBidVolume = array_sum(array_column($bids, 'volume_traded'));
        $totalAskVolume = array_sum(array_column($asks, 'volume_traded'));

        $totalBidsFromHeader = (int) ($totals['total_bids'] ?? $totalBidVolume);
        $totalAsksFromHeader = (int) ($totals['total_asks'] ?? $totalAskVolume);

        // نسبة الاختلال: > 1 = ضغط شراء, < 1 = ضغط بيع
        $imbalanceRatio = $totalAskVolume > 0
            ? $totalBidVolume / $totalAskVolume
            : ($totalBidVolume > 0 ? 5.0 : 1.0);

        // أفضل bid وask
        $bestBid = ! empty($bids) ? (float) ($bids[0]['order_price'] ?? 0) : 0.0;
        $bestAsk = ! empty($asks) ? (float) ($asks[0]['order_price'] ?? 0) : 0.0;

        $spreadAbs = $bestAsk > 0 && $bestBid > 0 ? $bestAsk - $bestBid : 0.0;
        $spreadPct = $bestBid > 0 ? ($spreadAbs / $bestBid) * 100 : 0.0;

        // مستويات الدعم والمقاومة من الـ depth
        $supportLevel    = $this->findSupportLevel($bids);
        $resistanceLevel = $this->findResistanceLevel($asks);

        // جودة الـ depth: عدد المستويات المتاحة
        $depthLevels = max(count($bids), count($asks));
        $depthQuality = min($depthLevels / 5, 1.0); // score بين 0 و1 بحد عند 5 مستويات

        // نسبة التصويت (imbalance) كـ score بين 0 و1
        $imbalanceScore = min($imbalanceRatio / 3.0, 1.0); // عند ratio=3 يصل لـ 1.0

        return [
            'total_bid_volume'    => $totalBidVolume,
            'total_ask_volume'    => $totalAskVolume,
            'total_bids_orders'   => $totalBidsFromHeader,
            'total_asks_orders'   => $totalAsksFromHeader,
            'imbalance_ratio'     => round($imbalanceRatio, 4),
            'imbalance_score'     => round($imbalanceScore, 4),
            'best_bid'            => $bestBid,
            'best_ask'            => $bestAsk,
            'spread_abs'          => round($spreadAbs, 6),
            'spread_pct'          => round($spreadPct, 4),
            'support_level'       => $supportLevel,
            'resistance_level'    => $resistanceLevel,
            'depth_levels'        => $depthLevels,
            'depth_quality'       => round($depthQuality, 4),
        ];
    }

    /**
     * مستوى الدعم = السعر الذي يوجد عنده أكبر كمية bids.
     *
     * @param  list<array<string, mixed>> $bids
     */
    private function findSupportLevel(array $bids): float
    {
        if (empty($bids)) {
            return 0.0;
        }

        $maxVolume = 0;
        $supportPrice = (float) ($bids[0]['order_price'] ?? 0);

        foreach ($bids as $level) {
            $vol = (int) ($level['volume_traded'] ?? 0);
            if ($vol > $maxVolume) {
                $maxVolume    = $vol;
                $supportPrice = (float) ($level['order_price'] ?? 0);
            }
        }

        return $supportPrice;
    }

    /**
     * مستوى المقاومة = السعر الذي يوجد عنده أكبر كمية asks.
     *
     * @param  list<array<string, mixed>> $asks
     */
    private function findResistanceLevel(array $asks): float
    {
        if (empty($asks)) {
            return 0.0;
        }

        $maxVolume = 0;
        $resistancePrice = (float) ($asks[0]['order_price'] ?? 0);

        foreach ($asks as $level) {
            $vol = (int) ($level['volume_traded'] ?? 0);
            if ($vol > $maxVolume) {
                $maxVolume        = $vol;
                $resistancePrice  = (float) ($level['order_price'] ?? 0);
            }
        }

        return $resistancePrice;
    }
}
