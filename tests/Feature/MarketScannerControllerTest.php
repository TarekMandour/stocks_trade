<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Services\MarketAnalyzerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketScannerControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    #[Test]
    public function index_page_loads_successfully(): void
    {
        $this->get(route('analysis.index'))
            ->assertOk()
            ->assertViewIs('analysis.index');
    }

    #[Test]
    public function index_page_contains_token_input_and_analyze_button(): void
    {
        $response = $this->get(route('analysis.index'));

        $response->assertSee('name="token"', escape: false);
        $response->assertSee('تشغيل التحليل');
    }

    // -------------------------------------------------------------------------
    // run (POST /analyze)
    // -------------------------------------------------------------------------

    #[Test]
    public function run_requires_token(): void
    {
        $this->post(route('analysis.run'), [])
            ->assertSessionHasErrors(['token']);
    }

    #[Test]
    public function run_rejects_token_shorter_than_10_chars(): void
    {
        $this->post(route('analysis.run'), ['token' => 'short'])
            ->assertSessionHasErrors(['token']);
    }

    #[Test]
    public function run_redirects_to_results_on_success(): void
    {
        $this->mock(MarketAnalyzerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('valid-token-123')
                ->andReturn(AnalysisRun::factory()->forToday()->create());
        });

        $this->post(route('analysis.run'), ['token' => 'valid-token-123'])
            ->assertRedirect(route('analysis.results'))
            ->assertSessionHas('success');
    }

    #[Test]
    public function run_redirects_to_results_with_error_on_failure(): void
    {
        $this->mock(MarketAnalyzerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new \RuntimeException('API timeout'));
        });

        $this->post(route('analysis.run'), ['token' => 'valid-token-123'])
            ->assertRedirect(route('analysis.results'))
            ->assertSessionHas('error');
    }

    #[Test]
    public function token_is_not_persisted_in_session_or_database(): void
    {
        $this->mock(MarketAnalyzerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(AnalysisRun::factory()->forToday()->create());
        });

        $this->post(route('analysis.run'), ['token' => 'mysecrettoken99']);

        // التوكن لا يجب أن يظهر في الـ session
        $this->assertFalse(session()->has('token'));
        // لا يوجد عمود token في قاعدة البيانات أصلًا (نتحقق من الـ analysis_runs)
        $this->assertDatabaseMissing('analysis_runs', ['notes' => 'mysecrettoken99']);
    }

    // -------------------------------------------------------------------------
    // results (GET /results)
    // -------------------------------------------------------------------------

    #[Test]
    public function results_page_loads_when_no_run_exists(): void
    {
        $this->get(route('analysis.results'))
            ->assertOk()
            ->assertViewIs('analysis.results')
            ->assertSee('لا توجد نتائج لهذا اليوم');
    }

    #[Test]
    public function results_page_shows_todays_run_by_default(): void
    {
        $run = AnalysisRun::factory()->forToday()->create([
            'day_candidates_count'   => 8,
            'swing_candidates_count' => 6,
        ]);

        $this->get(route('analysis.results'))
            ->assertOk()
            ->assertViewHas('run', fn ($viewRun) => $viewRun->id === $run->id);
    }

    #[Test]
    public function results_page_accepts_date_filter(): void
    {
        $run = AnalysisRun::factory()->create(['run_date' => '2026-07-05']);

        $this->get(route('analysis.results', ['date' => '2026-07-05']))
            ->assertOk()
            ->assertViewHas('run', fn ($viewRun) => $viewRun->id === $run->id);
    }

    #[Test]
    public function results_page_shows_no_run_for_date_with_no_data(): void
    {
        $this->get(route('analysis.results', ['date' => '2020-01-01']))
            ->assertOk()
            ->assertViewHas('run', null);
    }

    #[Test]
    public function results_page_shows_day_trade_and_swing_results(): void
    {
        $run = AnalysisRun::factory()->forToday()->create();

        AnalysisResult::factory()->count(5)->dayTrade()->create(['analysis_run_id' => $run->id]);
        AnalysisResult::factory()->count(4)->swing()->create(['analysis_run_id' => $run->id]);

        $response = $this->get(route('analysis.results'));

        $response->assertOk();
        $response->assertViewHas('run');

        $viewRun = $response->viewData('run');
        $this->assertCount(5, $viewRun->dayTradeResults);
        $this->assertCount(4, $viewRun->swingResults);
    }

    #[Test]
    public function results_page_passes_available_dates_to_view(): void
    {
        AnalysisRun::factory()->create(['run_date' => '2026-07-01']);
        AnalysisRun::factory()->create(['run_date' => '2026-07-02']);
        AnalysisRun::factory()->create(['run_date' => '2026-07-03']);

        $this->get(route('analysis.results'))
            ->assertOk()
            ->assertViewHas('availableDates');
    }

    #[Test]
    public function results_page_passes_current_date_to_view(): void
    {
        $this->get(route('analysis.results'))
            ->assertOk()
            ->assertViewHas('date', today()->toDateString());
    }
}
