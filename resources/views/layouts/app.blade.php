<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EGX Market Scanner')</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Cairo', Tahoma, Arial, sans-serif; }</style>
    @endif
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen antialiased">

    {{-- Navbar --}}
    <header class="bg-slate-900/80 backdrop-blur-sm border-b border-slate-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-14 flex items-center justify-between">

            {{-- Logo --}}
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
                <span class="font-bold text-sm text-white tracking-wide">EGX Scanner</span>
                <span class="text-slate-600 text-xs hidden sm:inline">البورصة المصرية</span>
            </div>

            {{-- Nav links --}}
            <nav class="flex items-center gap-1">
                <a href="{{ route('analysis.index') }}"
                   class="px-3 py-1.5 rounded-lg text-sm transition-all
                          {{ request()->routeIs('analysis.index')
                             ? 'bg-slate-800 text-white font-medium'
                             : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                    تحليل جديد
                </a>
                <a href="{{ route('analysis.results') }}"
                   class="px-3 py-1.5 rounded-lg text-sm transition-all
                          {{ request()->routeIs('analysis.results')
                             ? 'bg-slate-800 text-white font-medium'
                             : 'text-slate-400 hover:text-white hover:bg-slate-800/60' }}">
                    النتائج
                </a>
            </nav>

        </div>
    </header>

    {{-- Flash messages --}}
    @foreach (['success' => 'emerald', 'error' => 'red', 'info' => 'blue'] as $type => $color)
        @if (session($type))
            <div class="max-w-7xl mx-auto px-6 mt-4">
                <div class="flex items-center gap-3 bg-{{ $color }}-950/60 border border-{{ $color }}-800/60
                            text-{{ $color }}-300 rounded-xl px-4 py-3 text-sm">
                    @if ($type === 'success')
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    @elseif ($type === 'error')
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    @else
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                    <span>{{ session($type) }}</span>
                </div>
            </div>
        @endif
    @endforeach

    {{-- Content --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="border-t border-slate-800/50 mt-16 py-6 text-center text-xs text-slate-600">
        EGX Scanner — للاستخدام الداخلي فقط. هذا النظام لا يُمثّل نصيحة استثمارية.
    </footer>

</body>
</html>
