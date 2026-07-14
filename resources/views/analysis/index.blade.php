@extends('layouts.app')

@section('title', 'تحليل السوق المصري — EGX Scanner')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center">
<div class="w-full max-w-md">

    {{-- Hero --}}
    <div class="text-center mb-10">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl
                    bg-emerald-500/10 border border-emerald-500/20 mb-5">
            <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-2">تحليل السوق المصري</h1>
        <p class="text-slate-500 text-sm">أدخل Thndr API Token لتشغيل تحليل البورصة المصرية EGX</p>
    </div>

    {{-- Card --}}
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-7 shadow-2xl shadow-black/40">

        <form method="POST" action="{{ route('analysis.run') }}" id="analyzeForm" novalidate>
            @csrf

            {{-- Token field --}}
            <div class="mb-6">
                <label for="token" class="block text-xs font-semibold text-slate-400 uppercase tracking-widest mb-2">
                    API Token
                </label>
                <div class="relative">
                    <input
                        type="password"
                        id="token"
                        name="token"
                        placeholder="eyJ0eXAiOiJKV1Qi..."
                        autocomplete="off"
                        spellcheck="false"
                        class="w-full bg-slate-800/80 border
                               @error('token') border-red-500/70 focus:ring-red-500/40
                               @else border-slate-700/60 focus:ring-emerald-500/30 @enderror
                               text-slate-100 rounded-xl px-4 py-3 pr-10 text-sm
                               focus:outline-none focus:ring-2 focus:border-transparent
                               placeholder-slate-600 transition-all font-mono"
                        required
                    >
                    {{-- Eye toggle --}}
                    <button type="button" id="toggleVisibility"
                            class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition">
                        <svg id="eyeIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
                @error('token')
                    <p class="mt-2 text-red-400 text-xs flex items-center gap-1">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        {{ $message }}
                    </p>
                @enderror
                <p class="mt-2.5 text-slate-600 text-xs">
                    التوكن لا يُحفظ ويُستخدم لهذه الجلسة فقط
                </p>
            </div>

            {{-- Analyze button --}}
            <button type="submit" id="submitBtn"
                    class="w-full relative bg-emerald-600 hover:bg-emerald-500 active:scale-[0.98]
                           disabled:opacity-50 disabled:cursor-not-allowed disabled:scale-100
                           text-white font-semibold py-3 rounded-xl transition-all duration-200
                           flex items-center justify-center gap-2 text-sm shadow-lg shadow-emerald-900/30">
                <span id="btnIdle" class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    تشغيل التحليل
                </span>
                <span id="btnLoading" class="hidden flex items-center gap-2">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    جاري تحليل السوق...
                </span>
            </button>
        </form>

    </div>

    {{-- Info cards --}}
    <div class="mt-5 grid grid-cols-2 gap-3">
        <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-4 text-center">
            <div class="text-emerald-400 font-bold text-lg mb-0.5">Day Trade</div>
            <div class="text-slate-500 text-xs leading-relaxed">أفضل 10 فرص تداول يومي</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-4 text-center">
            <div class="text-blue-400 font-bold text-lg mb-0.5">Swing</div>
            <div class="text-slate-500 text-xs leading-relaxed">أفضل 10 مرشحين للسوينج</div>
        </div>
    </div>

    {{-- Today's status --}}
    @if ($todayRun ?? null)
        <div class="mt-4 flex items-center justify-center gap-2 text-xs">
            @if ($todayRun->status === 'completed')
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="text-slate-500">
                    يوجد تحليل لليوم ({{ $todayRun->finished_at?->format('H:i') }}) —
                    <a href="{{ route('analysis.results') }}" class="text-emerald-500 hover:text-emerald-400 underline underline-offset-2">عرض النتائج</a>
                </span>
            @elseif ($todayRun->status === 'failed')
                <span class="w-2 h-2 rounded-full bg-red-500"></span>
                <span class="text-slate-500">آخر تحليل فشل</span>
            @endif
        </div>
    @endif

</div>
</div>

<script>
// Toggle password visibility
document.getElementById('toggleVisibility').addEventListener('click', function () {
    const input = document.getElementById('token');
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    this.classList.toggle('text-emerald-400', isPassword);
    this.classList.toggle('text-slate-500', !isPassword);
});

// Loading state on submit
document.getElementById('analyzeForm').addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('btnIdle').classList.add('hidden');
    document.getElementById('btnLoading').classList.remove('hidden');
});
</script>
@endsection

