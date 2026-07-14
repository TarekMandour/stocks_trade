@php
    $reasons = $result->reasons ?? [];
    $metrics = $result->metrics ?? [];
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    {{-- Reasons --}}
    <div>
        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">أسباب الترشيح</h4>
        @if (empty($reasons))
            <p class="text-slate-700 text-xs">لا توجد أسباب مسجّلة</p>
        @else
            <ul class="space-y-2">
                @foreach ($reasons as $reason)
                    <li class="flex gap-2.5 text-xs">
                        <span class="w-1 h-1 rounded-full bg-emerald-500 mt-1.5 flex-shrink-0"></span>
                        <span class="text-slate-300 leading-relaxed">{{ $reason }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Metrics --}}
    <div>
        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-3">Metrics</h4>
        @if (empty($metrics))
            <p class="text-slate-700 text-xs">—</p>
        @else
            <div class="grid grid-cols-2 gap-x-6 gap-y-2">
                @foreach ($metrics as $key => $value)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-500 text-xs">{{ $key }}</span>
                        <span class="text-slate-200 text-xs font-mono font-medium">
                            {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
