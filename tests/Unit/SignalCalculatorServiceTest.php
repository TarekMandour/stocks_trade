<?php

namespace Tests\Unit;

use App\Models\AnalysisResult;
use App\Services\CandleAnalyzerService;
use App\Services\SignalCalculatorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignalCalculatorServiceTest extends TestCase
{
    private SignalCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignalCalculatorService(new CandleAnalyzerService);
    }

    #[Test]
    public function it_returns_all_required_keys_for_every_signal(): void
    {
        $signals = [
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH,
            AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY,
            AnalysisResult::SWING_SIGNAL_ACCUMULATION,
            AnalysisResult::SWING_SIGNAL_MOMENTUM,
            AnalysisResult::SIGNAL_WATCH_ONLY,
        ];

        foreach ($signals as $signal) {
            $result = $this->service->calculate($signal, $this->makeStock(), $this->makeDepthMetrics());

            $this->assertArrayHasKey('entry_price', $result, "Missing entry_price for {$signal}");
            $this->assertArrayHasKey('entry_from', $result, "Missing entry_from for {$signal}");
            $this->assertArrayHasKey('entry_to', $result, "Missing entry_to for {$signal}");
            $this->assertArrayHasKey('stop_loss', $result, "Missing stop_loss for {$signal}");
            $this->assertArrayHasKey('target_1', $result, "Missing target_1 for {$signal}");
            $this->assertArrayHasKey('target_2', $result, "Missing target_2 for {$signal}");
        }
    }

    #[Test]
    public function pullback_buy_uses_entry_zone_not_single_price(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            $this->makeStock(),
            $this->makeDepthMetrics(['support_level' => 9.80]),
        );

        $this->assertNotNull($result['entry_from']);
        $this->assertNotNull($result['entry_to']);
        $this->assertNull($result['entry_price']);
        $this->assertGreaterThan($result['entry_from'], $result['entry_to']);
    }

    #[Test]
    public function breakout_watch_uses_single_entry_price(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH,
            $this->makeStock(),
            $this->makeDepthMetrics(['resistance_level' => 10.50]),
        );

        $this->assertNotNull($result['entry_price']);
        $this->assertNull($result['entry_from']);
        $this->assertNull($result['entry_to']);
    }

    #[Test]
    public function stop_loss_is_below_entry_for_day_trade_signals(): void
    {
        foreach ([
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH,
            AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY,
        ] as $signal) {
            $result = $this->service->calculate($signal, $this->makeStock(), $this->makeDepthMetrics());

            $entry = $result['entry_price'] ?? $result['entry_from'];

            $this->assertNotNull($result['stop_loss'], "stop_loss null for {$signal}");
            $this->assertLessThan($entry, $result['stop_loss'], "stop_loss not below entry for {$signal}");
        }
    }

    #[Test]
    public function target_2_is_greater_than_target_1(): void
    {
        $signals = [
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH,
            AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY,
            AnalysisResult::SWING_SIGNAL_ACCUMULATION,
            AnalysisResult::SWING_SIGNAL_MOMENTUM,
        ];

        foreach ($signals as $signal) {
            $result = $this->service->calculate($signal, $this->makeStock(), $this->makeDepthMetrics());

            $this->assertGreaterThan(
                $result['target_1'],
                $result['target_2'],
                "target_2 not greater than target_1 for {$signal}"
            );
        }
    }

    #[Test]
    public function watch_only_returns_all_null_levels(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::SIGNAL_WATCH_ONLY,
            $this->makeStock(),
            $this->makeDepthMetrics(),
        );

        $this->assertNull($result['entry_price']);
        $this->assertNull($result['entry_from']);
        $this->assertNull($result['entry_to']);
        $this->assertNull($result['stop_loss']);
        $this->assertNull($result['target_1']);
        $this->assertNull($result['target_2']);
    }

    #[Test]
    public function swing_accumulation_uses_entry_zone(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::SWING_SIGNAL_ACCUMULATION,
            $this->makeStock(['low_52_week' => 7.00]),
            $this->makeDepthMetrics(['support_level' => 7.20]),
        );

        $this->assertNotNull($result['entry_from']);
        $this->assertNotNull($result['entry_to']);
        $this->assertNull($result['entry_price']);
    }

    #[Test]
    public function pullback_targets_are_calculated_from_the_upper_entry_bound_not_the_current_high(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            $this->makeStock([
                'last_trade_price' => 10.25,
                'high_price' => 10.25,
                'high_price_limit' => 11.50,
            ]),
            $this->makeDepthMetrics(['support_level' => 10.20]),
        );

        $this->assertGreaterThan($result['entry_to'], $result['target_1']);
        $this->assertGreaterThan(10.25, $result['target_1']);
        $this->assertGreaterThan($result['target_1'], $result['target_2']);
    }

    #[Test]
    public function entry_zone_never_uses_stale_support_above_the_current_price(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY,
            $this->makeStock(['last_trade_price' => 10.25]),
            $this->makeDepthMetrics(['support_level' => 11.00]),
            [
                '2026-07-13T07:50:00Z' => 11.00,
                '2026-07-13T08:50:00Z' => 11.10,
                '2026-07-13T09:50:00Z' => 11.05,
            ],
        );

        $this->assertLessThanOrEqual(10.25, $result['entry_from']);
        $this->assertLessThanOrEqual(10.25, $result['entry_to']);
    }

    #[Test]
    public function breakout_with_no_room_before_the_upper_price_limit_returns_no_trade_levels(): void
    {
        $result = $this->service->calculate(
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH,
            $this->makeStock([
                'high_price' => 10.55,
                'high_price_limit' => 10.60,
            ]),
            $this->makeDepthMetrics(),
        );

        $this->assertNull($result['entry_price']);
        $this->assertNull($result['stop_loss']);
        $this->assertNull($result['target_1']);
        $this->assertNull($result['target_2']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makeStock(array $overrides = []): array
    {
        return array_merge([
            'last_trade_price' => 10.25,
            'open_price' => 10.00,
            'high_price' => 10.55,
            'low_price' => 9.90,
            'close_price' => 10.25,
            'previous_close' => 10.00,
            'high_52_week' => 15.00,
            'low_52_week' => 7.00,
            'high_price_limit' => 11.50,
            'low_price_limit' => 9.00,
            'bid_price' => 10.24,
            'ask_price' => 10.26,
            'round_digits' => 2,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function makeDepthMetrics(array $overrides = []): array
    {
        return array_merge([
            'support_level' => 9.95,
            'resistance_level' => 10.50,
            'best_bid' => 10.24,
            'best_ask' => 10.26,
            'spread_pct' => 0.20,
            'imbalance_ratio' => 1.2,
        ], $overrides);
    }
}
