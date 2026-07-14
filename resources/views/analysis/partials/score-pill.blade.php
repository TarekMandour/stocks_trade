@php
    $val = (float) $score;
    if ($type === 'risk') {
        [$ring, $text] = $val <= 30
            ? ['border-emerald-500/60', 'text-emerald-400']
            : ($val <= 60
                ? ['border-amber-500/60', 'text-amber-400']
                : ['border-red-500/60',   'text-red-400']);
    } else {
        [$ring, $text] = $val >= 70
            ? ['border-emerald-500/60', 'text-emerald-400']
            : ($val >= 40
                ? ['border-amber-500/60', 'text-amber-400']
                : ['border-slate-600',    'text-slate-500']);
    }
@endphp
<span class="score-ring border {{ $ring }} {{ $text }}" title="{{ $val }}">
    {{ number_format($val, 0) }}
</span>
