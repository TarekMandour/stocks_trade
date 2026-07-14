<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisResult extends Model
{
    use HasFactory;

    public const TYPE_DAY_TRADE = 'day_trade';
    public const TYPE_SWING = 'swing';

    public const DAY_SIGNAL_PULLBACK_BUY = 'pullback_buy';
    public const DAY_SIGNAL_BREAKOUT_WATCH = 'breakout_watch';
    public const DAY_SIGNAL_MOMENTUM_BUY = 'momentum_buy';
    public const SIGNAL_WATCH_ONLY = 'watch_only';

    public const SWING_SIGNAL_ACCUMULATION = 'swing_accumulation_candidate';
    public const SWING_SIGNAL_MOMENTUM = 'swing_momentum_candidate';

    protected $fillable = [
        'analysis_run_id',
        'analysis_type',
        'rank',

        'asset_id',
        'market_id',
        'symbol_code',
        'reuters',
        'symbol_state',
        'arb_name',
        'eng_name',
        'sector_name',

        'price',
        'change_value',
        'change_percent',
        'open_price',
        'high_price',
        'low_price',
        'close_price',
        'previous_close',
        'ref_price',

        'bid_price',
        'ask_price',
        'bid_volume',
        'ask_volume',

        'total_value',
        'total_volume',
        'total_trades',

        'avg_5_day',
        'avg_30_day',
        'avg_90_day',

        'high_52_week',
        'low_52_week',

        'eps',
        'pe_ratio',

        'signal',
        'entry_price',
        'entry_from',
        'entry_to',
        'stop_loss',
        'target_1',
        'target_2',

        'opportunity_score',
        'risk_score',
        'confidence_score',

        'reasons',
        'metrics',
        'marketwatch_snapshot',
        'depth_snapshot',
    ];

    protected $casts = [
        'reasons' => 'array',
        'metrics' => 'array',
        'marketwatch_snapshot' => 'array',
        'depth_snapshot' => 'array',
    ];

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }

    public function isDayTrade(): bool
    {
        return $this->analysis_type === self::TYPE_DAY_TRADE;
    }

    public function isSwing(): bool
    {
        return $this->analysis_type === self::TYPE_SWING;
    }
}