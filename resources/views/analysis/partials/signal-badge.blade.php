@php
    $map = [
        'pullback_buy'                 => ['label' => 'Pullback Buy',   'dot' => 'bg-emerald-400', 'text' => 'text-emerald-300', 'bg' => 'bg-emerald-950/60 border-emerald-800/60'],
        'breakout_watch'              => ['label' => 'Breakout',       'dot' => 'bg-amber-400',   'text' => 'text-amber-300',   'bg' => 'bg-amber-950/60   border-amber-800/60'],
        'momentum_buy'                => ['label' => 'Momentum',       'dot' => 'bg-blue-400',    'text' => 'text-blue-300',    'bg' => 'bg-blue-950/60    border-blue-800/60'],
        'swing_accumulation_candidate'=> ['label' => 'Accumulation',   'dot' => 'bg-purple-400',  'text' => 'text-purple-300',  'bg' => 'bg-purple-950/60  border-purple-800/60'],
        'swing_momentum_candidate'    => ['label' => 'Swing Momentum', 'dot' => 'bg-indigo-400',  'text' => 'text-indigo-300',  'bg' => 'bg-indigo-950/60  border-indigo-800/60'],
        'watch_only'                  => ['label' => 'Watch Only',     'dot' => 'bg-slate-500',   'text' => 'text-slate-400',   'bg' => 'bg-slate-800/40   border-slate-700/40'],
    ];
    $item = $map[$signal] ?? ['label' => $signal, 'dot' => 'bg-slate-500', 'text' => 'text-slate-400', 'bg' => 'bg-slate-800/40 border-slate-700/40'];
@endphp
<span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-md border {{ $item['bg'] }} {{ $item['text'] }} whitespace-nowrap">
    <span class="w-1.5 h-1.5 rounded-full {{ $item['dot'] }}"></span>
    {{ $item['label'] }}
</span>
