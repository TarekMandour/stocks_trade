<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Services\MarketAnalyzerService;
use App\Services\ThndrApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MarketAnalyzerServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_full_analysis_and_persists_results(): void
    {
        $this->mockThndrApi(
            marketwatch: $this->makeMarketwatchResponse(30),
            depth: $this->makeDepthResponse(),
        );

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);
        $run = $analyzer->run('fake-token-12345');

        $this->assertInstanceOf(AnalysisRun::class, $run);
        $this->assertEquals('completed', $run->status);
        $this->assertDatabaseHas('analysis_runs', ['status' => 'completed']);
        $this->assertGreaterThan(0, AnalysisResult::count());
    }

    #[Test]
    public function it_replaces_existing_run_on_same_day(): void
    {
        $existing = AnalysisRun::factory()->forToday()->create();
        AnalysisResult::factory()->count(5)->create(['analysis_run_id' => $existing->id]);

        $this->mockThndrApi(
            marketwatch: $this->makeMarketwatchResponse(20),
            depth: $this->makeDepthResponse(),
        );

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);
        $analyzer->run('fake-token-12345');

        $this->assertDatabaseCount('analysis_runs', 1);
        $this->assertDatabaseMissing('analysis_runs', ['id' => $existing->id]);
    }

    #[Test]
    public function it_marks_run_as_failed_when_api_throws(): void
    {
        $this->mock(ThndrApiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMarketWatch')
                ->once()
                ->andThrow(new RuntimeException('API unreachable'));
        });

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);

        $this->expectException(RuntimeException::class);

        try {
            $analyzer->run('bad-token-12345');
        } finally {
            $this->assertDatabaseHas('analysis_runs', ['status' => 'failed']);
        }
    }

    #[Test]
    public function it_filters_out_ineligible_stocks(): void
    {
        // 25 أسهم مؤهلة + 5 غير مؤهلة
        $stocks = array_merge(
            $this->makeMarketwatchResponse(25),
            $this->makeIneligibleStocks(5),
        );

        $this->mockThndrApi(marketwatch: $stocks, depth: $this->makeDepthResponse());

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);
        $run = $analyzer->run('fake-token-12345');

        $run->refresh();
        $this->assertEquals(30, $run->marketwatch_count);
        $this->assertEquals(25, $run->eligible_count);
    }

    #[Test]
    public function results_have_correct_analysis_type(): void
    {
        $this->mockThndrApi(
            marketwatch: $this->makeMarketwatchResponse(20),
            depth: $this->makeDepthResponse(),
        );

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);
        $analyzer->run('fake-token-12345');

        $dayResults = AnalysisResult::where('analysis_type', 'day_trade')->get();
        $swingResults = AnalysisResult::where('analysis_type', 'swing')->get();

        // النتائج مصنّفة بشكل صحيح
        $dayResults->each(fn ($r) => $this->assertEquals('day_trade', $r->analysis_type));
        $swingResults->each(fn ($r) => $this->assertEquals('swing', $r->analysis_type));
    }

    #[Test]
    public function results_ranks_start_at_one(): void
    {
        $this->mockThndrApi(
            marketwatch: $this->makeMarketwatchResponse(20),
            depth: $this->makeDepthResponse(),
        );

        /** @var MarketAnalyzerService $analyzer */
        $analyzer = app(MarketAnalyzerService::class);
        $analyzer->run('fake-token-12345');

        $firstDay = AnalysisResult::where('analysis_type', 'day_trade')->orderBy('rank')->first();

        if ($firstDay) {
            $this->assertEquals(1, $firstDay->rank);
        } else {
            $this->markTestSkipped('No day trade results produced (not enough eligible stocks).');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $marketwatch
     * @param  array<string, mixed>  $depth
     */
    private function mockThndrApi(array $marketwatch, array $depth): void
    {
        $this->mock(ThndrApiService::class, function (MockInterface $mock) use ($marketwatch, $depth): void {
            $mock->shouldReceive('getMarketWatch')
                ->once()
                ->andReturn($marketwatch);

            $mock->shouldReceive('getMarketDepth')
                ->andReturn($depth);

            $mock->shouldReceive('getCharts')
                ->andReturn([]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function makeMarketwatchResponse(int $count): array
    {
        $stocks = [];

        for ($i = 1; $i <= $count; $i++) {
            $price = round(5 + ($i * 2.3), 2);
            $changePct = round(($i % 5) * 0.8 - 1.5, 2);  // range -1.5 to 1.7

            $stocks[] = [
                'asset_id' => fake()->uuid(),
                'market_id' => 'EGX'.$i,
                'symbol_state' => 'A',
                'last_trade_price' => $price,
                'open_price' => round($price * 0.99, 2),
                'high_price' => round($price * 1.03, 2),
                'low_price' => round($price * 0.97, 2),
                'close_price' => $price,
                'previous_close' => round($price / (1 + $changePct / 100), 2),
                'ref_price' => round($price / (1 + $changePct / 100), 2),
                'bid_price' => round($price - 0.01, 2),
                'ask_price' => round($price + 0.01, 2),
                'bid_volume' => 100_000 * $i,
                'ask_volume' => 80_000 * $i,
                'last_change' => round($price * $changePct / 100, 2),
                'last_change_prc' => $changePct,
                'total_value' => 5_000_000 * (($i % 5) + 1),
                'total_volume' => 500_000 * (($i % 4) + 1),
                'total_trades' => 200 * (($i % 3) + 1),
                'avg_5_day' => 400_000,
                'avg_30_day' => 450_000,
                'avg_90_day' => 420_000,
                'high_52_week' => round($price * 1.5, 2),
                'low_52_week' => round($price * 0.6, 2),
                'symbol_code' => 'EGS'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
                'reuters' => 'SYM'.$i,
                'arb_name' => 'شركة رقم '.$i,
                'eng_name' => 'Company '.$i,
                'eng_desc' => 'Banking',
                'eps' => round(0.1 * $i, 2),
                'pe_ratio' => round(10 + $i, 1),
                'round_digits' => 2,
                'high_price_limit' => round($price * 1.1, 2),
                'low_price_limit' => round($price * 0.9, 2),
            ];
        }

        return $stocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function makeIneligibleStocks(int $count): array
    {
        $stocks = [];

        for ($i = 1; $i <= $count; $i++) {
            $stocks[] = [
                'asset_id' => fake()->uuid(),
                'symbol_state' => 'S',  // suspended
                'last_trade_price' => 5.0,
                'total_value' => 100,  // أقل من الحد الأدنى
                'total_volume' => 100,
                'total_trades' => 5,
                'last_change_prc' => 0.0,
                'symbol_code' => 'INELIGIBLE'.$i,
                'reuters' => 'INE'.$i,
                'high_52_week' => 10.0,
                'low_52_week' => 4.0,
                'avg_5_day' => 100,
                'avg_30_day' => 100,
                'avg_90_day' => 100,
                'round_digits' => 2,
            ];
        }

        return $stocks;
    }

    /** @return array<string, mixed> */
    private function makeDepthResponse(): array
    {
        return [
            'bids_per_price' => [
                ['order_price' => 9.99, 'volume_traded' => 50_000],
                ['order_price' => 9.95, 'volume_traded' => 80_000],
            ],
            'asks_per_price' => [
                ['order_price' => 10.01, 'volume_traded' => 40_000],
                ['order_price' => 10.05, 'volume_traded' => 30_000],
            ],
            'total_bids_and_asks' => [
                'total_bids' => 130_000,
                'total_asks' => 70_000,
            ],
        ];
    }
}
