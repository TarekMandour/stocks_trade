<?php

namespace App\Services;

use App\Models\AnalysisResult;

/**
 * يحسب نقاط الدخول / وقف الخسارة / الأهداف بناءً على نوع الإشارة.
 * يستخدم بيانات الشموع الأسبوعية (charts API) لتحسين دقة المستويات.
 */
class SignalCalculatorService
{
    public function __construct(
        private readonly CandleAnalyzerService $candleAnalyzer,
    ) {}

    /**
     * @param  array<string, mixed>      $stock
     * @param  array<string, float|int>  $depthMetrics
     * @param  array<string, float>      $candles       [timestamp => close_price]
     * @return array{
     *   entry_price: float|null,
     *   entry_from: float|null,
     *   entry_to: float|null,
     *   stop_loss: float,
     *   target_1: float,
     *   target_2: float
     * }
     */
    public function calculate(string $signal, array $stock, array $depthMetrics, array $candles = []): array
    {
        return match ($signal) {
            AnalysisResult::DAY_SIGNAL_PULLBACK_BUY   => $this->pullbackBuy($stock, $depthMetrics, $candles),
            AnalysisResult::DAY_SIGNAL_BREAKOUT_WATCH => $this->breakoutWatch($stock, $depthMetrics, $candles),
            AnalysisResult::DAY_SIGNAL_MOMENTUM_BUY   => $this->momentumBuy($stock, $depthMetrics, $candles),
            AnalysisResult::SWING_SIGNAL_ACCUMULATION => $this->swingAccumulation($stock, $depthMetrics, $candles),
            AnalysisResult::SWING_SIGNAL_MOMENTUM     => $this->swingMomentum($stock, $depthMetrics, $candles),
            default                                    => $this->watchOnly($stock),
        };
    }

    // -------------------------------------------------------------------------
    // Day Trade Signals
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $stock @param array<string, float|int> $depthMetrics @param array<string, float> $candles */
    private function pullbackBuy(array $stock, array $depthMetrics, array $candles): array
    {
        $cfg         = config('market_analysis.signals.pullback_buy');
        $low         = (float) ($stock['low_price'] ?? 0);
        $high        = (float) ($stock['high_price'] ?? 0);
        $digits      = $this->roundDigits($stock);
        $depthSupport = (float) ($depthMetrics['support_level'] ?? 0);

        // نختار أقوى مستوى دعم: depth أو الشموع الأسبوعية
        $candleMetrics = $this->candleAnalyzer->analyze($candles);
        $candleSupport = $candleMetrics['support'];

        $supportLevel = $depthSupport > 0 ? $depthSupport : $low;
        if ($candleSupport > 0 && $candleSupport > $low) {
            // نأخذ الأعلى بين دعم الـ depth والشموع (الأقرب للسعر)
            $supportLevel = max($supportLevel, $candleSupport);
        }

        $entryRef  = $supportLevel > 0 ? $supportLevel : $low;
        $entryFrom = round($entryRef, $digits);
        $entryTo   = round($entryFrom * (1 + 0.005), $digits);

        $stopLoss = round($entryFrom * (1 - $cfg['stop_buffer_pct'] / 100), $digits);
        $risk     = $entryFrom - $stopLoss;

        $target1 = round($entryFrom + ($risk * $cfg['target1_rr']), $digits);
        $target2 = round($entryFrom + ($risk * $cfg['target2_rr']), $digits);

        // الهدف لا يتجاوز الهاي اليومي للـ day trade
        $target1 = min($target1, $high);
        $target2 = min($target2, $high * 1.01);

        return [
            'entry_price' => null,
            'entry_from'  => $entryFrom,
            'entry_to'    => $entryTo,
            'stop_loss'   => $stopLoss,
            'target_1'    => $target1,
            'target_2'    => $target2,
        ];
    }

    /** @param array<string, mixed> $stock @param array<string, float|int> $depthMetrics @param array<string, float> $candles */
    private function breakoutWatch(array $stock, array $depthMetrics, array $candles): array
    {
        $cfg    = config('market_analysis.signals.breakout_watch');
        $high   = (float) ($stock['high_price'] ?? 0);
        $digits = $this->roundDigits($stock);

        // الحد الأعلى اليومي: نحاول high_price_limit أولاً ثم max_limit كبديل
        $rawLimit       = (float) ($stock['high_price_limit'] ?? $stock['max_limit'] ?? 0);
        $highPriceLimit = $rawLimit > 0 ? $rawLimit : $high * 1.10;

        $depthResistance = (float) ($depthMetrics['resistance_level'] ?? 0);

        // نحسّن مستوى المقاومة بالشموع الأسبوعية
        $candleMetrics    = $this->candleAnalyzer->analyze($candles);
        $candleResistance = $candleMetrics['resistance'];

        // نقطة المرجع: الأعلى بين الهاي اليومي ومقاومة الشموع (إذا كانت دون الحد)
        $breakoutRef = $high;
        foreach ([$depthResistance, $candleResistance] as $resistance) {
            if ($resistance > 0 && $resistance < $highPriceLimit && $resistance > $breakoutRef) {
                $breakoutRef = $resistance;
            }
        }

        // الدخول فوق الهاي بنسبة بسيطة، لا يتجاوز الحد الأعلى للسعر
        $entryPrice = round($breakoutRef * (1 + $cfg['entry_above_high_pct'] / 100), $digits);
        $entryPrice = min($entryPrice, $highPriceLimit);

        // وقف الخسارة أسفل نقطة المرجع
        $stopLoss = round($breakoutRef * (1 - $cfg['stop_below_high_pct'] / 100), $digits);

        // تحقق: إذا كان الوقف أعلى من أو يساوي الدخول، الصفقة غير قابلة للتنفيذ
        if ($stopLoss >= $entryPrice) {
            return $this->watchOnly($stock);
        }

        $risk    = $entryPrice - $stopLoss;
        $target1 = round($entryPrice + ($risk * $cfg['target1_rr']), $digits);
        $target2 = round($entryPrice + ($risk * $cfg['target2_rr']), $digits);

        return [
            'entry_price' => $entryPrice,
            'entry_from'  => null,
            'entry_to'    => null,
            'stop_loss'   => $stopLoss,
            'target_1'    => $target1,
            'target_2'    => $target2,
        ];
    }

    /** @param array<string, mixed> $stock @param array<string, float|int> $depthMetrics @param array<string, float> $candles */
    private function momentumBuy(array $stock, array $depthMetrics, array $candles): array
    {
        $cfg       = config('market_analysis.signals.momentum_buy');
        $lastPrice = (float) ($stock['last_trade_price'] ?? 0);
        $bestAsk   = (float) ($depthMetrics['best_ask'] ?? $lastPrice);
        $digits    = $this->roundDigits($stock);

        $entryPrice = $bestAsk > 0 ? $bestAsk : $lastPrice;
        $entryPrice = round($entryPrice, $digits);

        $stopLoss = round($entryPrice * (1 - $cfg['stop_buffer_pct'] / 100), $digits);
        $risk     = $entryPrice - $stopLoss;

        $target1 = round($entryPrice + ($risk * $cfg['target1_rr']), $digits);
        $target2 = round($entryPrice + ($risk * $cfg['target2_rr']), $digits);

        return [
            'entry_price' => $entryPrice,
            'entry_from'  => null,
            'entry_to'    => null,
            'stop_loss'   => $stopLoss,
            'target_1'    => $target1,
            'target_2'    => $target2,
        ];
    }

    // -------------------------------------------------------------------------
    // Swing Signals
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $stock @param array<string, float|int> $depthMetrics @param array<string, float> $candles */
    private function swingAccumulation(array $stock, array $depthMetrics, array $candles): array
    {
        $cfg       = config('market_analysis.signals.swing_accumulation_candidate');
        $low52     = (float) ($stock['low_52_week'] ?? 0);
        $lastPrice = (float) ($stock['last_trade_price'] ?? 0);
        $digits    = $this->roundDigits($stock);

        $depthSupport  = (float) ($depthMetrics['support_level'] ?? 0);
        $candleMetrics = $this->candleAnalyzer->analyze($candles);
        $candleSupport = $candleMetrics['support'];

        // نختار أفضل مستوى دعم: الأعلى بين الثلاثة (يعني الأقرب للسعر الحالي)
        $candidates = array_filter([$depthSupport, $candleSupport, $low52 * 1.02], fn ($v) => $v > 0);
        $entryRef   = ! empty($candidates) ? max($candidates) : $lastPrice;
        $entryRef   = min($entryRef, $lastPrice); // لا يتجاوز السعر الحالي

        $entryFrom = round($entryRef, $digits);
        $entryTo   = round($entryFrom * 1.01, $digits); // منطقة دخول 1%

        $stopLoss = round($entryFrom * (1 - $cfg['stop_buffer_pct'] / 100), $digits);
        // الوقف لا يقل عن 2% أسفل الـ low السنوي (حماية مضاعفة)
        if ($low52 > 0) {
            $stopLoss = max($stopLoss, round($low52 * 0.97, $digits));
        }

        $risk    = $entryFrom - $stopLoss;
        $target1 = round($entryFrom + ($risk * $cfg['target1_rr']), $digits);
        $target2 = round($entryFrom + ($risk * $cfg['target2_rr']), $digits);

        // نحدد الهدف الأول عند مستوى المقاومة من الشموع إذا كان منطقياً
        if ($candleMetrics['resistance'] > $entryFrom && $candleMetrics['resistance'] < $target2) {
            $target1 = round(min($target1, $candleMetrics['resistance']), $digits);
        }

        return [
            'entry_price' => null,
            'entry_from'  => $entryFrom,
            'entry_to'    => $entryTo,
            'stop_loss'   => $stopLoss,
            'target_1'    => $target1,
            'target_2'    => $target2,
        ];
    }

    /** @param array<string, mixed> $stock @param array<string, float|int> $depthMetrics @param array<string, float> $candles */
    private function swingMomentum(array $stock, array $depthMetrics, array $candles): array
    {
        $cfg       = config('market_analysis.signals.swing_momentum_candidate');
        $lastPrice = (float) ($stock['last_trade_price'] ?? 0);
        $bestAsk   = (float) ($depthMetrics['best_ask'] ?? $lastPrice);
        $digits    = $this->roundDigits($stock);

        $entryPrice = $bestAsk > 0 ? $bestAsk : $lastPrice;
        $entryPrice = round($entryPrice, $digits);

        // وقف الخسارة: نستخدم دعم الشموع إذا كان أعلى من الوقف المحسوب
        $candleMetrics = $this->candleAnalyzer->analyze($candles);
        $candleSupport = $candleMetrics['support'];

        $stopLoss = round($entryPrice * (1 - $cfg['stop_buffer_pct'] / 100), $digits);
        if ($candleSupport > 0 && $candleSupport < $entryPrice && $candleSupport > $stopLoss) {
            // وقف فوق دعم الشموع (وقف تقني أدق)
            $stopLoss = round($candleSupport * 0.99, $digits);
        }

        $risk    = $entryPrice - $stopLoss;
        $target1 = round($entryPrice + ($risk * $cfg['target1_rr']), $digits);
        $target2 = round($entryPrice + ($risk * $cfg['target2_rr']), $digits);

        return [
            'entry_price' => $entryPrice,
            'entry_from'  => null,
            'entry_to'    => null,
            'stop_loss'   => $stopLoss,
            'target_1'    => $target1,
            'target_2'    => $target2,
        ];
    }

    /** @param array<string, mixed> $stock */
    private function watchOnly(array $stock): array
    {
        return [
            'entry_price' => null,
            'entry_from'  => null,
            'entry_to'    => null,
            'stop_loss'   => null,
            'target_1'    => null,
            'target_2'    => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $stock */
    private function roundDigits(array $stock): int
    {
        return (int) ($stock['round_digits'] ?? 2);
    }
}
