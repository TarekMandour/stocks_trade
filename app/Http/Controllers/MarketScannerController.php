<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunAnalysisRequest;
use App\Models\AnalysisRun;
use App\Services\MarketAnalyzerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class MarketScannerController extends Controller
{
    public function __construct(
        private readonly MarketAnalyzerService $analyzer,
    ) {}

    /**
     * صفحة التحليل - حقل الـ token وزر Analyze.
     */
    public function index(): View
    {
        $todayRun = AnalysisRun::whereDate('run_date', today())->first();

        return view('analysis.index', compact('todayRun'));
    }

    /**
     * استقبال الـ token وتشغيل التحليل.
     * التوكن لا يُحفظ في قاعدة البيانات أبدًا.
     */
    public function run(RunAnalysisRequest $request): RedirectResponse
    {
        $token = $request->validated()['token'];

        try {
            $this->analyzer->run($token);
            session()->flash('success', 'تم تنفيذ التحليل بنجاح.');
        } catch (Throwable $e) {
            session()->flash('error', 'فشل التحليل: ' . $e->getMessage());
        }

        return redirect()->route('analysis.results');
    }

    /**
     * صفحة النتائج - تعرض نتائج اليوم الحالي أو يوم محدد.
     */
    public function results(Request $request): View
    {
        $date = $request->date
            ? \Illuminate\Support\Carbon::parse($request->date)->toDateString()
            : today()->toDateString();

        $run = AnalysisRun::with([
            'dayTradeResults' => fn ($q) => $q->orderBy('rank'),
            'swingResults'    => fn ($q) => $q->orderBy('rank'),
        ])
            ->whereDate('run_date', $date)
            ->first();

        // قائمة الأيام التي يوجد بها نتائج (للفلتر)
        $availableDates = AnalysisRun::orderByDesc('run_date')
            ->pluck('run_date')
            ->map(fn ($d) => $d->toDateString());

        return view('analysis.results', compact('run', 'date', 'availableDates'));
    }
}
