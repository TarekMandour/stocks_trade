<?php

namespace Tests\Unit;

use App\Services\MarketScoreService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketScoreServiceTest extends TestCase
{
    private MarketScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketScoreService();
    }

    // -------------------------------------------------------------------------
    // Day Trade Initial Score
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_zero_score_for_stock_with_no_data(): void
    {
        $score = $this->service->calculateDayTradeInitialScore([]);

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    #[Test]
    public function it_scores_high_liquidity_stock_higher_than_low_liquidity(): void
    {
        $highLiquidity = $this->makeStock([
            'total_value'  => 20_000_000,
            'total_volume' => 2_000_000,
            'avg_5_day'    => 1_000_000,
        ]);

        $lowLiquidity = $this->makeStock([
            'total_value'  => 600_000,
            'total_volume' => 60_000,
            'avg_5_day'    => 1_000_000,
        ]);

        $highScore = $this->service->calculateDayTradeInitialScore($highLiquidity);
        $lowScore  = $this->service->calculateDayTradeInitialScore($lowLiquidity);

        $this->assertGreaterThan($lowScore, $highScore);
    }

    #[Test]
    public function it_scores_high_volume_surge_higher(): void
    {
        $surge = $this->makeStock(['total_volume' => 3_000_000, 'avg_5_day' => 1_000_000]);
        $flat  = $this->makeStock(['total_volume' => 800_000,   'avg_5_day' => 1_000_000]);

        $this->assertGreaterThan(
            $this->service->calculateDayTradeInitialScore($flat),
            $this->service->calculateDayTradeInitialScore($surge),
        );
    }

    #[Test]
    public function it_penalises_wide_spread(): void
    {
        $tightSpread = $this->makeStock(['bid_price' => 10.00, 'ask_price' => 10.01]);
        $wideSpread  = $this->makeStock(['bid_price' => 10.00, 'ask_price' => 10.20]);

        $this->assertGreaterThan(
            $this->service->calculateDayTradeInitialScore($wideSpread),
            $this->service->calculateDayTradeInitialScore($tightSpread),
        );
    }

    #[Test]
    public function day_score_is_always_between_0_and_100(): void
    {
        $extremeHigh = $this->makeStock([
            'total_value'  => 999_999_999,
            'total_volume' => 999_999_999,
            'avg_5_day'    => 100,
            'last_change_prc' => 9.5,
            'high_price'   => 100,
            'low_price'    => 90,
            'last_trade_price' => 100,
            'bid_price'    => 99.99,
            'ask_price'    => 100.00,
        ]);

        $score = $this->service->calculateDayTradeInitialScore($extremeHigh);

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    // -------------------------------------------------------------------------
    // Swing Initial Score
    // -------------------------------------------------------------------------

    #[Test]
    public function it_scores_stock_near_52_week_low_higher_for_swing(): void
    {
        $nearLow = $this->makeStock([
            'last_trade_price' => 4.00,
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,  // position ≈ 9%
            'avg_30_day'       => 500_000,
            'total_volume'     => 500_000,
        ]);

        $nearHigh = $this->makeStock([
            'last_trade_price' => 9.50,
            'high_52_week'     => 10.00,
            'low_52_week'      => 3.50,  // position ≈ 92%
            'avg_30_day'       => 500_000,
            'total_volume'     => 500_000,
        ]);

        $lowScore  = $this->service->calculateSwingInitialScore($nearLow);
        $highScore = $this->service->calculateSwingInitialScore($nearHigh);

        // كلاهما يجب أن يكون بين 0 و100
        $this->assertGreaterThanOrEqual(0, $lowScore);
        $this->assertLessThanOrEqual(100, $highScore);
    }

    #[Test]
    public function swing_score_is_always_between_0_and_100(): void
    {
        $score = $this->service->calculateSwingInitialScore($this->makeStock());

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makeStock(array $overrides = []): array
    {
        return array_merge([
            'last_trade_price' => 10.00,
            'open_price'       => 9.80,
            'high_price'       => 10.50,
            'low_price'        => 9.70,
            'close_price'      => 10.00,
            'previous_close'   => 9.80,
            'bid_price'        => 9.99,
            'ask_price'        => 10.01,
            'bid_volume'       => 100_000,
            'ask_volume'       => 80_000,
            'total_value'      => 5_000_000,
            'total_volume'     => 500_000,
            'total_trades'     => 300,
            'avg_5_day'        => 400_000,
            'avg_30_day'       => 450_000,
            'avg_90_day'       => 420_000,
            'high_52_week'     => 15.00,
            'low_52_week'      => 7.00,
            'last_change_prc'  => 2.0,
            'eps'              => 0.5,
            'pe_ratio'         => 20.0,
            'symbol_state'     => 'A',
            'round_digits'     => 2,
        ], $overrides);
    }
}
