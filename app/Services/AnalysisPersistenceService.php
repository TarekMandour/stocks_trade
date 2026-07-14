<?php

namespace App\Services;

use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مسؤول عن حفظ نتائج التحليل في قاعدة البيانات.
 * يدعم الاستبدال: إذا وُجد run لنفس اليوم يُحذف ويُعاد الإنشاء.
 */
class AnalysisPersistenceService
{
    /**
     * يُنشئ أو يستبدل الـ run الخاص باليوم المحدد.
     */
    public function upsertRun(string $date): AnalysisRun
    {
        $existing = AnalysisRun::whereDate('run_date', $date)->first();

        if ($existing) {
            Log::info('[Persistence] Replacing existing run for date', ['date' => $date, 'id' => $existing->id]);
            $existing->delete(); // cascade يحذف النتائج تلقائيًا
        }

        return AnalysisRun::create([
            'run_date'   => $date,
            'status'     => 'pending',
            'started_at' => now(),
        ]);
    }

    /**
     * تحديث إحصائيات الـ run بعد التصفية.
     */
    public function updateRunStats(AnalysisRun $run, int $marketwatchCount, int $eligibleCount): void
    {
        $run->update([
            'marketwatch_count' => $marketwatchCount,
            'eligible_count'    => $eligibleCount,
        ]);
    }

    /**
     * حفظ نتائج التحليل النهائية ضمن transaction.
     *
     * @param list<array<string, mixed>> $dayResults
     * @param list<array<string, mixed>> $swingResults
     */
    public function saveResults(
        AnalysisRun $run,
        array $dayResults,
        array $swingResults,
        array $configSnapshot = []
    ): void {
        DB::transaction(function () use ($run, $dayResults, $swingResults, $configSnapshot) {
            $rows = [];

            foreach ($dayResults as $rank => $result) {
                $rows[] = $this->buildRow($run->id, AnalysisResult::TYPE_DAY_TRADE, $rank + 1, $result);
            }

            foreach ($swingResults as $rank => $result) {
                $rows[] = $this->buildRow($run->id, AnalysisResult::TYPE_SWING, $rank + 1, $result);
            }

            if (! empty($rows)) {
                // insert in chunks to avoid query length limits
                foreach (array_chunk($rows, 50) as $chunk) {
                    AnalysisResult::insert($chunk);
                }
            }

            $run->update([
                'status'                => 'completed',
                'finished_at'           => now(),
                'day_candidates_count'  => count($dayResults),
                'swing_candidates_count'=> count($swingResults),
                'config_snapshot'       => $configSnapshot,
            ]);

            Log::info('[Persistence] Run saved', [
                'run_id'       => $run->id,
                'day_count'    => count($dayResults),
                'swing_count'  => count($swingResults),
            ]);
        });
    }

    /**
     * يُعلّم الـ run بالفشل.
     */
    public function markFailed(AnalysisRun $run, string $errorMessage): void
    {
        $run->update([
            'status'        => 'failed',
            'finished_at'   => now(),
            'error_message' => $errorMessage,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildRow(int $runId, string $type, int $rank, array $result): array
    {
        $stock   = $result['stock'] ?? [];
        $signals = $result['signals'] ?? [];
        $scores  = $result['scores'] ?? [];
        $now     = now()->toDateTimeString();

        return [
            'analysis_run_id'      => $runId,
            'analysis_type'        => $type,
            'rank'                 => $rank,

            'asset_id'             => $stock['asset_id'] ?? null,
            'market_id'            => $stock['market_id'] ?? null,
            'symbol_code'          => $stock['symbol_code'] ?? '',
            'reuters'              => $stock['reuters'] ?? null,
            'symbol_state'         => $stock['symbol_state'] ?? null,
            'arb_name'             => $stock['arb_name'] ?? null,
            'eng_name'             => $stock['eng_name'] ?? null,
            'sector_name'          => $stock['eng_desc'] ?? null,

            'price'                => $stock['last_trade_price'] ?? null,
            'change_value'         => $stock['last_change'] ?? null,
            'change_percent'       => $stock['last_change_prc'] ?? null,
            'open_price'           => $stock['open_price'] ?? null,
            'high_price'           => $stock['high_price'] ?? null,
            'low_price'            => $stock['low_price'] ?? null,
            'close_price'          => $stock['close_price'] ?? null,
            'previous_close'       => $stock['previous_close'] ?? null,
            'ref_price'            => $stock['ref_price'] ?? null,

            'bid_price'            => $stock['bid_price'] ?? null,
            'ask_price'            => $stock['ask_price'] ?? null,
            'bid_volume'           => $stock['bid_volume'] ?? null,
            'ask_volume'           => $stock['ask_volume'] ?? null,

            'total_value'          => $stock['total_value'] ?? null,
            'total_volume'         => $stock['total_volume'] ?? null,
            'total_trades'         => $stock['total_trades'] ?? null,

            'avg_5_day'            => $stock['avg_5_day'] ?? null,
            'avg_30_day'           => $stock['avg_30_day'] ?? null,
            'avg_90_day'           => $stock['avg_90_day'] ?? null,

            'high_52_week'         => $stock['high_52_week'] ?? null,
            'low_52_week'          => $stock['low_52_week'] ?? null,

            'eps'                  => $stock['eps'] ?? null,
            'pe_ratio'             => $stock['pe_ratio'] ?? null,

            'signal'               => $scores['signal'] ?? 'watch_only',
            'entry_price'          => $signals['entry_price'] ?? null,
            'entry_from'           => $signals['entry_from'] ?? null,
            'entry_to'             => $signals['entry_to'] ?? null,
            'stop_loss'            => $signals['stop_loss'] ?? null,
            'target_1'             => $signals['target_1'] ?? null,
            'target_2'             => $signals['target_2'] ?? null,

            'opportunity_score'    => $scores['opportunity_score'] ?? 0,
            'risk_score'           => $scores['risk_score'] ?? 0,
            'confidence_score'     => $scores['confidence_score'] ?? 0,

            'reasons'              => json_encode($scores['reasons'] ?? []),
            'metrics'              => json_encode($scores['metrics'] ?? []),
            'marketwatch_snapshot' => json_encode($stock),
            'depth_snapshot'       => json_encode($result['depth'] ?? []),

            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }
}
