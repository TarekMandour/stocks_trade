<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ThndrApiService
{
    private string $baseUrl;

    private string $market;

    private int $timeout;

    private int $retryTimes;

    private int $retrySleep;

    public function __construct()
    {
        $this->baseUrl    = config('market_analysis.thndr.base_url');
        $this->market     = config('market_analysis.thndr.market');
        $this->timeout    = config('market_analysis.thndr.timeout');
        $this->retryTimes = config('market_analysis.thndr.retry_times');
        $this->retrySleep = config('market_analysis.thndr.retry_sleep');
    }

    /**
     * جلب بيانات المراقبة اليومية لجميع الأسهم.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function getMarketWatch(string $token): array
    {
        $url = "{$this->baseUrl}/assets/marketwatch";

        Log::info('[ThndrApi] Fetching marketwatch', ['market' => $this->market]);

        try {
            // $response = Http::withToken($token)
            //     ->timeout($this->timeout)
            //     ->retry($this->retryTimes, $this->retrySleep)
            //     ->get($url, ['market' => $this->market]);

            // $response->throw();
$response =  file_get_contents(storage_path('app/start_day.json'));
            $data = json_decode($response, true);

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected marketwatch response format.');
            }

            $stocks = $this->extractStocksList($data);

            Log::info('[ThndrApi] Marketwatch fetched', ['count' => count($stocks)]);

            return $stocks;
        } catch (RequestException $e) {
            Log::error('[ThndrApi] Marketwatch request failed', [
                'status'  => $e->response?->status(),
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException("Thndr API error (marketwatch): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * جلب عمق السوق لسهم بعينه.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function getMarketDepth(string $token, string $assetId): array
    {
        $url = "{$this->baseUrl}/market-depth/{$assetId}";

        try {
            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->retry($this->retryTimes, $this->retrySleep)
                ->get($url);

            $response->throw();

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException("Unexpected depth response for asset: {$assetId}");
            }

            return $data;
        } catch (RequestException $e) {
            Log::warning('[ThndrApi] Market depth request failed', [
                'asset_id' => $assetId,
                'status'   => $e->response?->status(),
                'message'  => $e->getMessage(),
            ]);
            // نعيد مصفوفة فارغة حتى لا يوقف فشل سهم واحد التحليل كاملًا
            return [
                'bids_per_price'        => [],
                'asks_per_price'        => [],
                'total_bids_and_asks'   => ['total_bids' => 0, 'total_asks' => 0],
            ];
        }
    }

    /**
     * جلب بيانات الشموع (candles) لأسهم بعينها.
     *
     * @param  list<string>  $assetIds  قائمة بـ UUIDs للأسهم (حتى ~20 في المرة)
     * @param  string        $option    خيار الفترة الزمنية: '1w-1h', '1d-1min', '1M', إلخ
     * @return array<string, array<string, float>>  keyed by asset_id, then timestamp => close_price
     *
     * @throws RuntimeException
     */
    public function getCharts(string $token, array $assetIds, string $option = ''): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $url    = "{$this->baseUrl}/charts";
        $option = $option ?: config('market_analysis.thndr.chart_option', '1w-1h');

        Log::info('[ThndrApi] Fetching charts', [
            'count'  => count($assetIds),
            'option' => $option,
        ]);

        try {
            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->retry($this->retryTimes, $this->retrySleep)
                ->get($url, [
                    'market'    => $this->market,
                    'asset_ids' => implode(',', $assetIds),
                    'option'    => $option,
                ]);

            $response->throw();

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected charts response format.');
            }

            Log::info('[ThndrApi] Charts fetched', ['assets' => count($data)]);

            return $data;
        } catch (RequestException $e) {
            Log::warning('[ThndrApi] Charts request failed', [
                'status'  => $e->response?->status(),
                'message' => $e->getMessage(),
            ]);

            // نعيد مصفوفة فارغة حتى لا يوقف فشل الـ charts التحليل كاملاً
            return [];
        }
    }

    /**
     * يستخرج قائمة الأسهم من الـ response بغض النظر عن شكل التغليف.
     *
     * يدعم الأشكال التالية:
     * - قائمة مباشرة: [{...}, {...}]
     * - مُغلَّف بـ key معروف: {"data": [...]} أو {"assets": [...]} إلخ
     * - أي قيمة array تحتوي على stock objects داخل الـ response
     *
     * @param  array<mixed> $response
     * @return list<array<string, mixed>>
     */
    private function extractStocksList(array $response): array
    {
        // قائمة مباشرة من stock objects
        if (isset($response[0]) && is_array($response[0])) {
            return array_values($response);
        }

        // مُغلَّف تحت key معروف
        $knownKeys = ['data', 'assets', 'items', 'results', 'stocks', 'list'];
        foreach ($knownKeys as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && ! empty($response[$key])) {
                $first = reset($response[$key]);
                if (is_array($first)) {
                    return array_values($response[$key]);
                }
            }
        }

        // fallback: استخرج أول value هي array تحتوي على stock objects
        $arrays = array_values(array_filter($response, function ($value): bool {
            if (! is_array($value) || empty($value)) {
                return false;
            }
            $first = reset($value);

            return is_array($first);
        }));

        if (! empty($arrays)) {
            Log::warning('[ThndrApi] Marketwatch wrapped in unknown key, extracted first array value.', [
                'keys' => array_keys($response),
            ]);

            return array_values($arrays[0]);
        }

        // الـ response نفسه قد يكون associative array لسهم واحد
        if (isset($response['asset_id'])) {
            return [$response];
        }

        Log::warning('[ThndrApi] Could not extract stocks list from response', [
            'keys' => array_keys($response),
        ]);

        return [];
    }
}
