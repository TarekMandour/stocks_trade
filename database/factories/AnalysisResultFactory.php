<?php

namespace Database\Factories;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisResult>
 */
class AnalysisResultFactory extends Factory
{
    protected $model = AnalysisResult::class;

    public function definition(): array
    {
        $price      = $this->faker->randomFloat(2, 1, 200);
        $changePct  = $this->faker->randomFloat(2, -5, 5);
        $high       = $price * 1.03;
        $low        = $price * 0.97;
        $stopLoss   = round($price * 0.97, 2);
        $target1    = round($price * 1.03, 2);
        $target2    = round($price * 1.06, 2);

        return [
            'analysis_run_id'   => AnalysisRun::factory(),
            'analysis_type'     => AnalysisResult::TYPE_DAY_TRADE,
            'rank'              => $this->faker->numberBetween(1, 10),

            'asset_id'          => $this->faker->uuid(),
            'market_id'         => strtoupper($this->faker->lexify('????')),
            'symbol_code'       => strtoupper($this->faker->lexify('EGS??????')),
            'reuters'           => strtoupper($this->faker->lexify('????')),
            'symbol_state'      => 'A',
            'arb_name'          => $this->faker->company() . ' للاستثمار',
            'eng_name'          => $this->faker->company() . ' Investment',
            'sector_name'       => $this->faker->randomElement(['Banking', 'Real Estate', 'Telecom', 'Health Care']),

            'price'             => $price,
            'change_value'      => round($price * $changePct / 100, 2),
            'change_percent'    => $changePct,
            'open_price'        => round($price * 0.99, 2),
            'high_price'        => round($high, 2),
            'low_price'         => round($low, 2),
            'close_price'       => $price,
            'previous_close'    => round($price / (1 + $changePct / 100), 2),
            'ref_price'         => round($price / (1 + $changePct / 100), 2),

            'bid_price'         => round($price - 0.01, 2),
            'ask_price'         => round($price + 0.01, 2),
            'bid_volume'        => $this->faker->numberBetween(10000, 500000),
            'ask_volume'        => $this->faker->numberBetween(10000, 500000),

            'total_value'       => $this->faker->randomFloat(2, 500_000, 50_000_000),
            'total_volume'      => $this->faker->numberBetween(50_000, 5_000_000),
            'total_trades'      => $this->faker->numberBetween(50, 5000),

            'avg_5_day'         => $this->faker->randomFloat(2, 200_000, 3_000_000),
            'avg_30_day'        => $this->faker->randomFloat(2, 200_000, 3_000_000),
            'avg_90_day'        => $this->faker->randomFloat(2, 200_000, 3_000_000),

            'high_52_week'      => round($price * 1.5, 2),
            'low_52_week'       => round($price * 0.6, 2),

            'eps'               => $this->faker->randomFloat(2, 0.1, 5),
            'pe_ratio'          => $this->faker->randomFloat(1, 5, 50),

            'signal'            => AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY,
            'entry_price'       => $price,
            'entry_from'        => null,
            'entry_to'          => null,
            'stop_loss'         => $stopLoss,
            'target_1'          => $target1,
            'target_2'          => $target2,

            'opportunity_score' => $this->faker->randomFloat(1, 40, 90),
            'risk_score'        => $this->faker->randomFloat(1, 10, 50),
            'confidence_score'  => $this->faker->randomFloat(1, 40, 85),

            'reasons'           => ['زخم إيجابي قوي', 'حجم تداول مرتفع'],
            'metrics'           => ['vol_ratio_5d' => 1.8, 'imbalance' => 1.5],
            'marketwatch_snapshot' => null,
            'depth_snapshot'    => null,
        ];
    }

    public function dayTrade(): static
    {
        return $this->state([
            'analysis_type' => AnalysisResult::TYPE_DAY_TRADE,
            'signal'        => AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY,
        ]);
    }

    public function swing(): static
    {
        return $this->state([
            'analysis_type' => AnalysisResult::TYPE_SWING,
            'signal'        => AnalysisResult::SWING_SIGNAL_ACCUMULATION,
        ]);
    }

    public function watchOnly(): static
    {
        return $this->state([
            'signal'            => AnalysisResult::SIGNAL_WATCH_ONLY,
            'entry_price'       => null,
            'stop_loss'         => null,
            'target_1'          => null,
            'target_2'          => null,
            'opportunity_score' => 10,
        ]);
    }
}
