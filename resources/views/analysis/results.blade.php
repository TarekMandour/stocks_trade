@extends('layouts.app')

@section('title', 'نتائج التحليل — EGX Scanner')

@section('content')

{{-- Page header --}}
<div class="flex flex-wrap items-start justify-between gap-4 mb-8">

    <div>
        <h1 class="text-xl font-bold text-white">نتائج التحليل</h1>
        <p class="text-slate-500 text-sm mt-1">
            {{ \Carbon\Carbon::parse($date)->translatedFormat('l، d F Y') }}
        </p>
    </div>

    {{-- Date filter --}}
    <form method="GET" action="{{ route('analysis.results') }}" class="flex items-center gap-2">
        <label class="text-xs text-slate-500 font-medium">عرض يوم:</label>
        <select name="date" onchange="this.form.submit()"
                class="bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg
                       px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500/40 cursor-pointer">
            <option value="{{ today()->toDateString() }}"
                    {{ $date === today()->toDateString() ? 'selected' : '' }}>
                اليوم
            </option>
            @foreach ($availableDates as $d)
                @if ($d !== today()->toDateString())
                    <option value="{{ $d }}" {{ $date === $d ? 'selected' : '' }}>
                        {{ \Carbon\Carbon::parse($d)->format('d/m/Y') }}
                    </option>
                @endif
            @endforeach
        </select>
    </form>
</div>

@if (! $run)
    {{-- Empty state --}}
    <div class="flex flex-col items-center justify-center py-32 text-center">
        <div class="w-16 h-16 rounded-2xl bg-slate-800/80 border border-slate-700/50
                    flex items-center justify-center mb-5">
            <svg class="w-7 h-7 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <h3 class="text-slate-400 font-semibold mb-1">لا توجد نتائج لهذا اليوم</h3>
        <p class="text-slate-600 text-sm mb-6">قم بتشغيل التحليل أولًا للحصول على التوصيات</p>
        <a href="{{ route('analysis.index') }}"
           class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium
                  px-5 py-2.5 rounded-xl transition-all active:scale-95">
            تشغيل التحليل
        </a>
    </div>

@else

    {{-- Summary stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
        @php
            $stats = [
                ['label' => 'إجمالي السوق',  'value' => number_format($run->marketwatch_count), 'color' => 'text-slate-300',  'icon' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3'],
                ['label' => 'مؤهلة للتحليل', 'value' => number_format($run->eligible_count),    'color' => 'text-yellow-400',  'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['label' => 'Day Trade',      'value' => number_format($run->day_candidates_count),   'color' => 'text-emerald-400', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
                ['label' => 'Swing',          'value' => number_format($run->swing_candidates_count), 'color' => 'text-blue-400',    'icon' => 'M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z'],
            ];
        @endphp
        @foreach ($stats as $stat)
            <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs text-slate-500 font-medium">{{ $stat['label'] }}</span>
                    <svg class="w-4 h-4 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $stat['icon'] }}"/>
                    </svg>
                </div>
                <div class="text-2xl font-bold {{ $stat['color'] }}">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ===================== DAY TRADE ===================== --}}
    <section class="mb-10">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-1 h-5 bg-emerald-500 rounded-full"></div>
            <h2 class="text-base font-bold text-white">Top 10 Day Trade Opportunities</h2>
            <span class="text-xs text-slate-600 bg-slate-800 px-2 py-0.5 rounded-full">
                {{ $run->dayTradeResults->count() }} نتيجة
            </span>
        </div>

        @if ($run->dayTradeResults->isEmpty())
            <div class="bg-slate-900/40 border border-slate-800/40 rounded-xl py-10 text-center text-slate-600 text-sm">
                لا توجد نتائج Day Trade لهذا اليوم
            </div>
        @else
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                <th class="px-4 py-3 text-right font-medium w-8">#</th>
                                <th class="px-4 py-3 text-right font-medium">السهم</th>
                                <th class="px-4 py-3 text-right font-medium">السعر</th>
                                <th class="px-4 py-3 text-right font-medium">تغيير</th>
                                <th class="px-4 py-3 text-right font-medium">إشارة</th>
                                <th class="px-4 py-3 text-right font-medium">دخول</th>
                                <th class="px-4 py-3 text-right font-medium">وقف</th>
                                <th class="px-4 py-3 text-right font-medium">T1</th>
                                <th class="px-4 py-3 text-right font-medium">T2</th>
                                <th class="px-4 py-3 text-center font-medium">Opp</th>
                                <th class="px-4 py-3 text-center font-medium">Risk</th>
                                <th class="px-4 py-3 text-center font-medium">Conf</th>
                                <th class="px-4 py-3 w-16"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($run->dayTradeResults as $result)
                                <tr class="result-row hover:bg-slate-800/40 group">
                                    <td class="px-4 py-3 text-slate-600 font-mono text-xs">{{ $result->rank }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-white text-sm leading-tight">
                                            {{ $result->reuters ?? $result->symbol_code }}
                                        </div>
                                        <div class="text-slate-500 text-xs mt-0.5 max-w-[160px] truncate">
                                            {{ $result->arb_name }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-slate-200 font-medium">
                                        {{ number_format($result->price, 2) }}
                                    </td>
                                    <td class="px-4 py-3 font-mono font-semibold
                                               {{ $result->change_percent >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                                        {{ $result->change_percent >= 0 ? '+' : '' }}{{ number_format($result->change_percent, 2) }}%
                                    </td>
                                    <td class="px-4 py-3">
                                        @include('analysis.partials.signal-badge', ['signal' => $result->signal])
                                    </td>
                                    <td class="px-4 py-3 font-mono text-amber-300 text-xs">
                                        @if ($result->entry_from && $result->entry_to)
                                            {{ number_format($result->entry_from, 2) }}<span class="text-slate-600">–</span>{{ number_format($result->entry_to, 2) }}
                                        @elseif ($result->entry_price)
                                            {{ number_format($result->entry_price, 2) }}
                                        @else
                                            <span class="text-slate-700">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-red-400 text-xs font-medium">
                                        {{ $result->stop_loss ? number_format($result->stop_loss, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-emerald-400 text-xs">
                                        {{ $result->target_1 ? number_format($result->target_1, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-emerald-300 text-xs">
                                        {{ $result->target_2 ? number_format($result->target_2, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->opportunity_score, 'type' => 'opp'])
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->risk_score, 'type' => 'risk'])
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->confidence_score, 'type' => 'conf'])
                                    </td>
                                    <td class="px-4 py-3">
                                        <button onclick="toggleDetails('day-{{ $result->id }}')"
                                                class="text-slate-600 hover:text-slate-300 transition p-1 rounded"
                                                title="تفاصيل">
                                            <svg id="arrow-day-{{ $result->id }}" class="w-4 h-4 transition-transform duration-200"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                {{-- Details row --}}
                                <tr id="day-{{ $result->id }}" class="hidden bg-slate-950/60">
                                    <td colspan="13" class="px-6 py-5 border-b border-slate-800/60">
                                        @include('analysis.partials.result-details', ['result' => $result])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    {{-- ===================== SWING ===================== --}}
    <section>
        <div class="flex items-center gap-3 mb-4">
            <div class="w-1 h-5 bg-blue-500 rounded-full"></div>
            <h2 class="text-base font-bold text-white">Top 10 Swing Candidates</h2>
            <span class="text-xs text-slate-600 bg-slate-800 px-2 py-0.5 rounded-full">
                {{ $run->swingResults->count() }} نتيجة
            </span>
        </div>

        @if ($run->swingResults->isEmpty())
            <div class="bg-slate-900/40 border border-slate-800/40 rounded-xl py-10 text-center text-slate-600 text-sm">
                لا توجد نتائج Swing لهذا اليوم
            </div>
        @else
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                <th class="px-4 py-3 text-right font-medium w-8">#</th>
                                <th class="px-4 py-3 text-right font-medium">السهم</th>
                                <th class="px-4 py-3 text-right font-medium">السعر</th>
                                <th class="px-4 py-3 text-right font-medium">تغيير</th>
                                <th class="px-4 py-3 text-right font-medium">إشارة</th>
                                <th class="px-4 py-3 text-right font-medium">دخول</th>
                                <th class="px-4 py-3 text-right font-medium">وقف</th>
                                <th class="px-4 py-3 text-right font-medium">T1</th>
                                <th class="px-4 py-3 text-right font-medium">T2</th>
                                <th class="px-4 py-3 text-center font-medium">Opp</th>
                                <th class="px-4 py-3 text-center font-medium">Risk</th>
                                <th class="px-4 py-3 text-center font-medium">Conf</th>
                                <th class="px-4 py-3 w-16"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            @foreach ($run->swingResults as $result)
                                <tr class="result-row hover:bg-slate-800/40 group">
                                    <td class="px-4 py-3 text-slate-600 font-mono text-xs">{{ $result->rank }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-white text-sm leading-tight">
                                            {{ $result->reuters ?? $result->symbol_code }}
                                        </div>
                                        <div class="text-slate-500 text-xs mt-0.5 max-w-[160px] truncate">
                                            {{ $result->arb_name }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-slate-200 font-medium">
                                        {{ number_format($result->price, 2) }}
                                    </td>
                                    <td class="px-4 py-3 font-mono font-semibold
                                               {{ $result->change_percent >= 0 ? 'text-emerald-400' : 'text-red-400' }}">
                                        {{ $result->change_percent >= 0 ? '+' : '' }}{{ number_format($result->change_percent, 2) }}%
                                    </td>
                                    <td class="px-4 py-3">
                                        @include('analysis.partials.signal-badge', ['signal' => $result->signal])
                                    </td>
                                    <td class="px-4 py-3 font-mono text-amber-300 text-xs">
                                        @if ($result->entry_from && $result->entry_to)
                                            {{ number_format($result->entry_from, 2) }}<span class="text-slate-600">–</span>{{ number_format($result->entry_to, 2) }}
                                        @elseif ($result->entry_price)
                                            {{ number_format($result->entry_price, 2) }}
                                        @else
                                            <span class="text-slate-700">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-red-400 text-xs font-medium">
                                        {{ $result->stop_loss ? number_format($result->stop_loss, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-emerald-400 text-xs">
                                        {{ $result->target_1 ? number_format($result->target_1, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-emerald-300 text-xs">
                                        {{ $result->target_2 ? number_format($result->target_2, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->opportunity_score, 'type' => 'opp'])
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->risk_score, 'type' => 'risk'])
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @include('analysis.partials.score-pill', ['score' => $result->confidence_score, 'type' => 'conf'])
                                    </td>
                                    <td class="px-4 py-3">
                                        <button onclick="toggleDetails('swing-{{ $result->id }}')"
                                                class="text-slate-600 hover:text-slate-300 transition p-1 rounded"
                                                title="تفاصيل">
                                            <svg id="arrow-swing-{{ $result->id }}" class="w-4 h-4 transition-transform duration-200"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr id="swing-{{ $result->id }}" class="hidden bg-slate-950/60">
                                    <td colspan="13" class="px-6 py-5 border-b border-slate-800/60">
                                        @include('analysis.partials.result-details', ['result' => $result])
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

@endif

<script>
function toggleDetails(id) {
    const row = document.getElementById(id);
    const isHidden = row.classList.contains('hidden');
    row.classList.toggle('hidden', !isHidden);

    // Rotate arrow
    const prefix = id.startsWith('day-') ? 'day-' : 'swing-';
    const num = id.replace(prefix, '');
    const arrow = document.getElementById('arrow-' + prefix + num);
    if (arrow) {
        arrow.style.transform = isHidden ? 'rotate(180deg)' : '';
    }
}
</script>
@endsection

