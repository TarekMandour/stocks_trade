<?php

namespace Tests\Unit;

use App\Models\AnalysisResult;
use App\Services\SwingAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SwingAnalyzerServiceTest extends TestCase
{
    private SwingAnalyzerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SwingAnalyzerService();
    }

    #[Test]
    public function it_returns_all_required_keys(): void
    {
        $result = $this->service->analyze($this->makeStock(), $this->makeDepthMetrics(), 50.0);

        $this->assertArrayHasKey('signal', $result);
        $this->assertArrayHasKey('opportunity_score', $result);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('confidence_score', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('metrics', $result);
    }

    #[Test]
    public function it_detects_accumulation_signal_near_52_week_low(): void
    {
        // position ≈ 10% (داخل lower_zone = 35%)
        $stock = $this->makeStock([
            'last_trade_price' => 4.00,
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,
            'total_volume'     => 700_000,
            'avg_30_day'       => 900_000,  // ratio ≈ 0.78 >= 0.6
        ]);

        $depth = $this->makeDepthMetrics(['imbalance_ratio' => 1.0, 'spread_pct' => 0.3]);

        $result = $this->service->analyze($stock, $depth, 45.0);

        $this->assertEquals(AnalysisResult::SWING_SIGNAL_ACCUMULATION, $result['signal']);
    }

    #[Test]
    public function it_detects_momentum_signal_near_52_week_high(): void
    {
        // position ≈ 85% (فوق upper_zone = 65%)
        $stock = $this->makeStock([
            'last_trade_price' => 9.20,
            'open_price'       => 9.00,
            'close_price'      => 9.20,   // bullish
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,
            'total_volume'     => 1_500_000,
            'avg_30_day'       => 1_000_000,  // ratio 1.5 >= 1.2
        ]);

        $depth = $this->makeDepthMetrics(['spread_pct' => 0.2]);

        $result = $this->service->analyze($stock, $depth, 60.0);

        $this->assertEquals(AnalysisResult::SWING_SIGNAL_MOMENTUM, $result['signal']);
    }

    #[Test]
    public function it_returns_watch_only_for_middle_zone_low_volume(): void
    {
        $stock = $this->makeStock([
            'last_trade_price' => 6.50,  // position ≈ 46% (middle zone)
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,
            'total_volume'     => 200_000,
            'avg_30_day'       => 800_000, // ratio 0.25 < 0.6
        ]);

        $result = $this->service->analyze($stock, $this->makeDepthMetrics(), 20.0);

        $this->assertEquals(AnalysisResult::SIGNAL_WATCH_ONLY, $result['signal']);
    }

    #[Test]
    public function scores_are_always_between_0_and_100(): void
    {
        $result = $this->service->analyze($this->makeStock(), $this->makeDepthMetrics(), 50.0);

        $this->assertGreaterThanOrEqual(0, $result['opportunity_score']);
        $this->assertLessThanOrEqual(100, $result['opportunity_score']);

        $this->assertGreaterThanOrEqual(0, $result['risk_score']);
        $this->assertLessThanOrEqual(100, $result['risk_score']);

        $this->assertGreaterThanOrEqual(0, $result['confidence_score']);
        $this->assertLessThanOrEqual(100, $result['confidence_score']);
    }

    #[Test]
    public function metrics_contains_52_week_position(): void
    {
        $result = $this->service->analyze($this->makeStock(), $this->makeDepthMetrics(), 50.0);

        $this->assertArrayHasKey('position_52w', $result['metrics']);
        $this->assertArrayHasKey('vol_ratio_30d', $result['metrics']);
        $this->assertArrayHasKey('imbalance', $result['metrics']);
    }

    #[Test]
    public function it_includes_eps_and_pe_in_metrics_when_available(): void
    {
        $stock  = $this->makeStock(['eps' => 0.8, 'pe_ratio' => 15.0]);
        $result = $this->service->analyze($stock, $this->makeDepthMetrics(), 50.0);

        $this->assertArrayHasKey('eps', $result['metrics']);
        $this->assertArrayHasKey('pe_ratio', $result['metrics']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makeStock(array $overrides = []): array
    {
        return array_merge([
            'last_trade_price' => 6.00,
            'open_price'       => 5.90,
            'close_price'      => 6.00,
            'high_price'       => 6.20,
            'low_price'        => 5.80,
            'previous_close'   => 5.90,
            'last_change_prc'  => 1.7,
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,
            'bid_price'        => 5.99,
            'ask_price'        => 6.01,
            'total_value'      => 3_000_000,
            'total_volume'     => 500_000,
            'total_trades'     => 250,
            'avg_30_day'       => 600_000,
            'avg_90_day'       => 580_000,
            'eps'              => 0.5,
            'pe_ratio'         => 12.0,
            'round_digits'     => 2,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function makeDepthMetrics(array $overrides = []): array
    {
        return array_merge([
            'imbalance_ratio'  => 1.1,
            'imbalance_score'  => 0.37,
            'spread_pct'       => 0.33,
            'spread_abs'       => 0.02,
            'best_bid'         => 5.99,
            'best_ask'         => 6.01,
            'support_level'    => 5.85,
            'resistance_level' => 6.20,
            'depth_quality'    => 0.7,
            'depth_levels'     => 3,
        ], $overrides);
    }
}
