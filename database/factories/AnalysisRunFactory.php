<?php

namespace Database\Factories;

use App\Models\AnalysisRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisRun>
 */
class AnalysisRunFactory extends Factory
{
    protected $model = AnalysisRun::class;

    public function definition(): array
    {
        return [
            'run_date'               => $this->faker->unique()->dateTimeBetween('-30 days', 'today')->format('Y-m-d'),
            'status'                 => 'completed',
            'started_at'             => now()->subMinutes(5),
            'finished_at'            => now(),
            'marketwatch_count'      => $this->faker->numberBetween(100, 300),
            'eligible_count'         => $this->faker->numberBetween(30, 100),
            'day_candidates_count'   => $this->faker->numberBetween(5, 10),
            'swing_candidates_count' => $this->faker->numberBetween(5, 10),
            'config_snapshot'        => null,
            'notes'                  => null,
            'error_message'          => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'finished_at' => null]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'        => 'failed',
            'finished_at'   => now(),
            'error_message' => 'API request failed.',
        ]);
    }

    public function forToday(): static
    {
        return $this->state(['run_date' => today()->toDateString()]);
    }
}
