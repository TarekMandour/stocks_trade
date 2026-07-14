<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_date',
        'status',
        'started_at',
        'finished_at',
        'marketwatch_count',
        'eligible_count',
        'day_candidates_count',
        'swing_candidates_count',
        'config_snapshot',
        'notes',
        'error_message',
    ];

    protected $casts = [
        'run_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'config_snapshot' => 'array',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(AnalysisResult::class);
    }

    public function dayTradeResults(): HasMany
    {
        return $this->hasMany(AnalysisResult::class)
            ->where('analysis_type', AnalysisResult::TYPE_DAY_TRADE)
            ->orderBy('rank');
    }

    public function swingResults(): HasMany
    {
        return $this->hasMany(AnalysisResult::class)
            ->where('analysis_type', AnalysisResult::TYPE_SWING)
            ->orderBy('rank');
    }
}