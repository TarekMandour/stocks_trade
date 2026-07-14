<?php

namespace Tests\Unit;

use App\Models\AnalysisResult;
use App\Services\DayTradeAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DayTradeAnalyzerServiceTest extends TestCase
{
    private DayTradeAnalyzerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DayTradeAnalyzerService();
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
    public function it_detects_momentum_buy_signal(): void
    {
        $stock = $this->makeStock([
            'last_change_prc'  => 3.5,   // > 2
            'last_trade_price' => 10.25,
            'high_price'       => 10.60,
            'low_price'        => 10.00,  // posInRange = 0.25/0.60 ≈ 0.42 (< 0.80, لا يُشغّل breakout)
            'total_volume'     => 1_500_000,
            'avg_5_day'        => 800_000,  // ratio ≈ 1.875 > 1.3
        ]);

        $depth = $this->makeDepthMetrics(['imbalance_ratio' => 1.5, 'spread_pct' => 0.2]);

        $result = $this->service->analyze($stock, $depth, 60.0);

        $this->assertEquals(AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY, $result['signal']);
    }

    #[Test]
    public function it_detects_breakout_watch_signal(): void
    {
        $stock = $this->makeStock([
            'last_change_prc'  => 2.0,
            'last_trade_price' => 10.48,  // قريب من الهاي
            'high_price'       => 10.50,
            'low_price'        => 10.00,  // pos_in_range ≈ 0.96 >= 0.80
            'total_volume'     => 1_300_000,
            'avg_5_day'        => 1_000_000,  // ratio 1.3 >= 1.2
        ]);

        $depth = $this->makeDepthMetrics(['spread_pct' => 0.2]);

        $result = $this->service->analyze($stock, $depth, 55.0);

        $this->assertEquals(AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH, $result['signal']);
    }

    #[Test]
    public function it_detects_pullback_buy_signal(): void
    {
        $stock = $this->makeStock([
            'last_change_prc'  => 0.5,
            'last_trade_price' => 10.08,  // قريب من اللو
            'high_price'       => 10.50,
            'low_price'        => 10.00,  // pos_in_range ≈ 0.16 <= 0.40
            'total_volume'     => 850_000,
            'avg_5_day'        => 1_000_000,  // ratio 0.85 >= 0.8
        ]);

        $depth = $this->makeDepthMetrics(['imbalance_ratio' => 1.2, 'spread_pct' => 0.3]);

        $result = $this->service->analyze($stock, $depth, 45.0);

        $this->assertEquals(AnalysisResult::DAY_SIGNAL_PULLBACK_BUY, $result['signal']);
    }

    #[Test]
    public function it_returns_watch_only_when_no_signal_matches(): void
    {
        $stock = $this->makeStock([
            'last_change_prc'  => 0.1,
            'last_trade_price' => 10.25,
            'high_price'       => 10.50,
            'low_price'        => 10.00,  // pos_in_range = 0.5 (neutral)
            'total_volume'     => 200_000,
            'avg_5_day'        => 1_000_000, // ratio 0.2 (very low)
        ]);

        $depth = $this->makeDepthMetrics(['imbalance_ratio' => 0.8, 'spread_pct' => 0.5]);

        $result = $this->service->analyze($stock, $depth, 15.0);

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
    public function reasons_is_always_an_array(): void
    {
        $result = $this->service->analyze($this->makeStock(), $this->makeDepthMetrics(), 50.0);

        $this->assertIsArray($result['reasons']);
    }

    #[Test]
    public function metrics_contains_required_keys(): void
    {
        $result = $this->service->analyze($this->makeStock(), $this->makeDepthMetrics(), 50.0);

        $this->assertArrayHasKey('change_pct', $result['metrics']);
        $this->assertArrayHasKey('pos_in_range', $result['metrics']);
        $this->assertArrayHasKey('vol_ratio_5d', $result['metrics']);
        $this->assertArrayHasKey('imbalance', $result['metrics']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makeStock(array $overrides = []): array
    {
        return array_merge([
            'last_trade_price' => 10.25,
            'open_price'       => 10.00,
            'high_price'       => 10.50,
            'low_price'        => 9.80,
            'close_price'      => 10.25,
            'previous_close'   => 10.00,
            'last_change_prc'  => 2.5,
            'bid_price'        => 10.24,
            'ask_price'        => 10.26,
            'bid_volume'       => 150_000,
            'ask_volume'       => 90_000,
            'total_value'      => 8_000_000,
            'total_volume'     => 800_000,
            'total_trades'     => 400,
            'avg_5_day'        => 600_000,
            'avg_30_day'       => 580_000,
            'avg_90_day'       => 550_000,
            'round_digits'     => 2,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function makeDepthMetrics(array $overrides = []): array
    {
        return array_merge([
            'imbalance_ratio'  => 1.3,
            'imbalance_score'  => 0.43,
            'spread_pct'       => 0.20,
            'spread_abs'       => 0.02,
            'best_bid'         => 10.24,
            'best_ask'         => 10.26,
            'support_level'    => 9.85,
            'resistance_level' => 10.50,
            'depth_quality'    => 0.8,
            'depth_levels'     => 4,
        ], $overrides);
    }
}
