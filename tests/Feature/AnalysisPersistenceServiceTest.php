<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Services\AnalysisPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalysisPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalysisPersistenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnalysisPersistenceService();
    }

    #[Test]
    public function it_creates_a_new_run_for_a_new_date(): void
    {
        $run = $this->service->upsertRun('2026-07-10');

        $this->assertInstanceOf(AnalysisRun::class, $run);
        $this->assertEquals('2026-07-10', $run->run_date->toDateString());
        $this->assertEquals('pending', $run->status);
    }

    #[Test]
    public function it_replaces_existing_run_for_same_date(): void
    {
        $old = AnalysisRun::factory()->create(['run_date' => '2026-07-10']);

        $new = $this->service->upsertRun('2026-07-10');

        $this->assertNotEquals($old->id, $new->id);
        $this->assertDatabaseMissing('analysis_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('analysis_runs', ['id' => $new->id]);
        $this->assertDatabaseCount('analysis_runs', 1);
    }

    #[Test]
    public function replacing_a_run_also_deletes_its_results(): void
    {
        $old = AnalysisRun::factory()->create(['run_date' => '2026-07-10']);
        AnalysisResult::factory()->count(5)->create(['analysis_run_id' => $old->id]);

        $this->service->upsertRun('2026-07-10');

        $this->assertDatabaseCount('analysis_results', 0);
    }

    #[Test]
    public function it_saves_day_trade_and_swing_results(): void
    {
        $run = AnalysisRun::factory()->pending()->create(['run_date' => today()->toDateString()]);

        $dayResults   = [$this->makeResult('day_trade'), $this->makeResult('day_trade')];
        $swingResults = [$this->makeResult('swing')];

        $this->service->saveResults($run, $dayResults, $swingResults);

        $this->assertDatabaseCount('analysis_results', 3);
        $this->assertDatabaseHas('analysis_results', ['analysis_type' => 'day_trade', 'rank' => 1]);
        $this->assertDatabaseHas('analysis_results', ['analysis_type' => 'day_trade', 'rank' => 2]);
        $this->assertDatabaseHas('analysis_results', ['analysis_type' => 'swing', 'rank' => 1]);
    }

    #[Test]
    public function it_marks_run_as_completed_after_saving(): void
    {
        $run = AnalysisRun::factory()->pending()->create(['run_date' => today()->toDateString()]);

        $this->service->saveResults($run, [$this->makeResult('day_trade')], []);

        $this->assertDatabaseHas('analysis_runs', ['id' => $run->id, 'status' => 'completed']);
    }

    #[Test]
    public function it_updates_candidate_counts_after_saving(): void
    {
        $run = AnalysisRun::factory()->pending()->create(['run_date' => today()->toDateString()]);

        $dayResults   = array_fill(0, 7, $this->makeResult('day_trade'));
        $swingResults = array_fill(0, 5, $this->makeResult('swing'));

        $this->service->saveResults($run, $dayResults, $swingResults);

        $run->refresh();
        $this->assertEquals(7, $run->day_candidates_count);
        $this->assertEquals(5, $run->swing_candidates_count);
    }

    #[Test]
    public function it_marks_run_as_failed(): void
    {
        $run = AnalysisRun::factory()->pending()->create(['run_date' => today()->toDateString()]);

        $this->service->markFailed($run, 'Connection timeout');

        $this->assertDatabaseHas('analysis_runs', [
            'id'            => $run->id,
            'status'        => 'failed',
            'error_message' => 'Connection timeout',
        ]);
    }

    #[Test]
    public function it_updates_run_stats(): void
    {
        $run = AnalysisRun::factory()->pending()->create(['run_date' => today()->toDateString()]);

        $this->service->updateRunStats($run, 250, 80);

        $this->assertDatabaseHas('analysis_runs', [
            'id'               => $run->id,
            'marketwatch_count'=> 250,
            'eligible_count'   => 80,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function makeResult(string $type): array
    {
        $stock = [
            'asset_id'         => fake()->uuid(),
            'market_id'        => 'TEST',
            'symbol_code'      => 'EGS' . strtoupper(fake()->lexify('??????')),
            'reuters'          => strtoupper(fake()->lexify('????')),
            'symbol_state'     => 'A',
            'arb_name'         => fake()->company(),
            'eng_name'         => fake()->company(),
            'eng_desc'         => 'Banking',
            'last_trade_price' => 10.0,
            'last_change'      => 0.2,
            'last_change_prc'  => 2.0,
            'open_price'       => 9.8,
            'high_price'       => 10.5,
            'low_price'        => 9.7,
            'close_price'      => 10.0,
            'previous_close'   => 9.8,
            'ref_price'        => 9.8,
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
            'high_52_week'     => 15.0,
            'low_52_week'      => 7.0,
            'eps'              => 0.5,
            'pe_ratio'         => 20.0,
        ];

        $signal = $type === 'day_trade'
            ? AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY
            : AnalysisResult::SWING_SIGNAL_ACCUMULATION;

        return [
            'stock'   => $stock,
            'scores'  => [
                'signal'            => $signal,
                'opportunity_score' => 65.0,
                'risk_score'        => 25.0,
                'confidence_score'  => 70.0,
                'reasons'           => ['زخم إيجابي قوي'],
                'metrics'           => ['vol_ratio_5d' => 1.8],
            ],
            'signals' => [
                'entry_price' => 10.01,
                'entry_from'  => null,
                'entry_to'    => null,
                'stop_loss'   => 9.80,
                'target_1'    => 10.30,
                'target_2'    => 10.60,
            ],
            'depth'   => [],
        ];
    }
}
