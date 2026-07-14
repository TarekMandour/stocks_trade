<?php

namespace Tests\Unit;

use App\Services\DepthAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepthAnalyzerServiceTest extends TestCase
{
    private DepthAnalyzerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DepthAnalyzerService();
    }

    #[Test]
    public function it_returns_neutral_imbalance_for_equal_bid_ask(): void
    {
        $depth = $this->makeDepth(
            bids: [['order_price' => 9.99, 'volume_traded' => 50_000]],
            asks: [['order_price' => 10.01, 'volume_traded' => 50_000]],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertEqualsWithDelta(1.0, $metrics['imbalance_ratio'], 0.01);
    }

    #[Test]
    public function it_detects_buy_pressure_when_bids_exceed_asks(): void
    {
        $depth = $this->makeDepth(
            bids: [['order_price' => 9.99, 'volume_traded' => 200_000]],
            asks: [['order_price' => 10.01, 'volume_traded' => 50_000]],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertGreaterThan(1.0, $metrics['imbalance_ratio']);
    }

    #[Test]
    public function it_detects_sell_pressure_when_asks_exceed_bids(): void
    {
        $depth = $this->makeDepth(
            bids: [['order_price' => 9.99, 'volume_traded' => 30_000]],
            asks: [['order_price' => 10.01, 'volume_traded' => 300_000]],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertLessThan(1.0, $metrics['imbalance_ratio']);
    }

    #[Test]
    public function it_calculates_spread_correctly(): void
    {
        $depth = $this->makeDepth(
            bids: [['order_price' => 10.00, 'volume_traded' => 100]],
            asks: [['order_price' => 10.10, 'volume_traded' => 100]],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertEqualsWithDelta(0.10, $metrics['spread_abs'], 0.001);
        $this->assertEqualsWithDelta(1.0, $metrics['spread_pct'], 0.01);
    }

    #[Test]
    public function it_handles_empty_depth_gracefully(): void
    {
        $metrics = $this->service->analyze([
            'bids_per_price'      => [],
            'asks_per_price'      => [],
            'total_bids_and_asks' => ['total_bids' => 0, 'total_asks' => 0],
        ]);

        $this->assertArrayHasKey('imbalance_ratio', $metrics);
        $this->assertArrayHasKey('spread_pct', $metrics);
        $this->assertArrayHasKey('support_level', $metrics);
        $this->assertArrayHasKey('resistance_level', $metrics);
        $this->assertEquals(0.0, $metrics['spread_abs']);
    }

    #[Test]
    public function it_finds_support_level_at_highest_bid_volume(): void
    {
        $depth = $this->makeDepth(
            bids: [
                ['order_price' => 9.99, 'volume_traded' => 10_000],
                ['order_price' => 9.95, 'volume_traded' => 80_000],  // largest
                ['order_price' => 9.90, 'volume_traded' => 5_000],
            ],
            asks: [],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertEquals(9.95, $metrics['support_level']);
    }

    #[Test]
    public function it_finds_resistance_level_at_highest_ask_volume(): void
    {
        $depth = $this->makeDepth(
            bids: [],
            asks: [
                ['order_price' => 10.01, 'volume_traded' => 5_000],
                ['order_price' => 10.05, 'volume_traded' => 90_000],  // largest
                ['order_price' => 10.10, 'volume_traded' => 20_000],
            ],
        );

        $metrics = $this->service->analyze($depth);

        $this->assertEquals(10.05, $metrics['resistance_level']);
    }

    #[Test]
    public function it_returns_all_required_keys(): void
    {
        $metrics = $this->service->analyze($this->makeDepth());

        $required = [
            'total_bid_volume', 'total_ask_volume',
            'imbalance_ratio', 'imbalance_score',
            'best_bid', 'best_ask',
            'spread_abs', 'spread_pct',
            'support_level', 'resistance_level',
            'depth_levels', 'depth_quality',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $metrics, "Missing key: $key");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>> $bids
     * @param  list<array<string, mixed>> $asks
     * @return array<string, mixed>
     */
    private function makeDepth(array $bids = [], array $asks = []): array
    {
        if (empty($bids)) {
            $bids = [['order_price' => 9.99, 'volume_traded' => 50_000]];
        }

        if (empty($asks)) {
            $asks = [['order_price' => 10.01, 'volume_traded' => 50_000]];
        }

        return [
            'bids_per_price' => $bids,
            'asks_per_price' => $asks,
            'total_bids_and_asks' => [
                'total_bids' => array_sum(array_column($bids, 'volume_traded')),
                'total_asks' => array_sum(array_column($asks, 'volume_traded')),
            ],
        ];
    }
}
