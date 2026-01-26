<?php

namespace App\Http\Controllers;

use App\Services\EnhancedMarketDataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EnhancedMarketDataController extends Controller
{
    protected $marketDataService;

    public function __construct(EnhancedMarketDataService $marketDataService)
    {
        $this->marketDataService = $marketDataService;
    }

    /**
     * Get comprehensive market data for a symbol
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getComprehensiveData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'market' => 'required|in:stocks,crypto,forex'
        ]);

        try {
            $data = $this->marketDataService->getComprehensiveMarketData(
                $validated['symbol'],
                $validated['market']
            );

            return response()->json([
                'success' => true,
                'symbol' => $validated['symbol'],
                'market' => $validated['market'],
                'data' => $data,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch market data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get aggregated signal strength
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getSignalStrength(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'market' => 'required|in:stocks,crypto,forex'
        ]);

        try {
            $marketData = $this->marketDataService->getComprehensiveMarketData(
                $validated['symbol'],
                $validated['market']
            );

            $signals = $this->marketDataService->calculateAggregatedSignalStrength($marketData);

            return response()->json([
                'success' => true,
                'symbol' => $validated['symbol'],
                'market' => $validated['market'],
                'signals' => $signals,
                'data_sources_used' => $this->getDataSourcesUsed($marketData),
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to calculate signal strength',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get economic indicators only
     * 
     * @return JsonResponse
     */
    public function getEconomicIndicators(): JsonResponse
    {
        try {
            // Use reflection to call protected method
            $reflection = new \ReflectionClass($this->marketDataService);
            $method = $reflection->getMethod('getEconomicIndicators');
            $method->setAccessible(true);
            
            $indicators = $method->invoke($this->marketDataService);

            return response()->json([
                'success' => true,
                'indicators' => $indicators,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch economic indicators',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get news sentiment for a symbol
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getNewsSentiment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:10',
            'market' => 'required|in:stocks,crypto,forex'
        ]);

        try {
            $reflection = new \ReflectionClass($this->marketDataService);
            $method = $reflection->getMethod('getNewsSentiment');
            $method->setAccessible(true);
            
            $sentiment = $method->invoke(
                $this->marketDataService,
                $validated['symbol'],
                $validated['market']
            );

            return response()->json([
                'success' => true,
                'symbol' => $validated['symbol'],
                'sentiment' => $sentiment,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch news sentiment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get currency exchange rates
     * 
     * @return JsonResponse
     */
    public function getExchangeRates(): JsonResponse
    {
        try {
            $reflection = new \ReflectionClass($this->marketDataService);
            $method = $reflection->getMethod('getExchangeRates');
            $method->setAccessible(true);
            
            $rates = $method->invoke($this->marketDataService);

            return response()->json([
                'success' => true,
                'rates' => $rates,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch exchange rates',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get market breadth indicators
     * 
     * @return JsonResponse
     */
    public function getMarketBreadth(): JsonResponse
    {
        try {
            $reflection = new \ReflectionClass($this->marketDataService);
            $method = $reflection->getMethod('getMarketBreadth');
            $method->setAccessible(true);
            
            $breadth = $method->invoke($this->marketDataService);

            return response()->json([
                'success' => true,
                'breadth' => $breadth,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch market breadth',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Health check for all integrated APIs
     * 
     * @return JsonResponse
     */
    public function healthCheck(): JsonResponse
    {
        $services = [
            'fred' => config('services.fred.api_key') ? 'configured' : 'not_configured',
            'twelvedata' => config('services.twelvedata.api_key') ? 'configured' : 'not_configured',
            'iexcloud' => config('services.iexcloud.api_key') ? 'configured' : 'not_configured',
            'coingecko' => 'available', // Free API
            'marketaux' => config('services.marketaux.api_key') ? 'configured' : 'not_configured',
            'newsdata' => config('services.newsdata.api_key') ? 'configured' : 'not_configured',
            'exchangerate' => 'available', // Free API
            'wallstreetbets' => 'available', // No key required
        ];

        $configuredCount = count(array_filter($services, fn($status) => $status === 'configured' || $status === 'available'));
        $totalCount = count($services);

        return response()->json([
            'success' => true,
            'services' => $services,
            'configured_services' => $configuredCount,
            'total_services' => $totalCount,
            'health_percentage' => round(($configuredCount / $totalCount) * 100, 2),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    /**
     * Get list of data sources used in market data
     * 
     * @param array $marketData
     * @return array
     */
    protected function getDataSourcesUsed(array $marketData): array
    {
        $sources = [];

        if (isset($marketData['economic_indicators']) && !empty($marketData['economic_indicators'])) {
            $sources[] = 'FRED (Economic Data)';
        }
        if (isset($marketData['stock_data']) && !empty($marketData['stock_data'])) {
            if (isset($marketData['stock_data']['iex'])) $sources[] = 'IEX Cloud';
            if (isset($marketData['stock_data']['twelvedata'])) $sources[] = 'Twelve Data';
        }
        if (isset($marketData['crypto_data']) && !empty($marketData['crypto_data'])) {
            if (isset($marketData['crypto_data']['coingecko'])) $sources[] = 'CoinGecko';
            if (isset($marketData['crypto_data']['binance'])) $sources[] = 'Binance';
        }
        if (isset($marketData['news_sentiment']) && !empty($marketData['news_sentiment'])) {
            $sources[] = 'MarketAux (News Sentiment)';
        }
        if (isset($marketData['social_sentiment']) && !empty($marketData['social_sentiment'])) {
            $sources[] = 'WallStreetBets';
        }
        if (isset($marketData['exchange_rates']) && !empty($marketData['exchange_rates'])) {
            $sources[] = 'Exchange Rates';
        }
        if (isset($marketData['market_breadth']) && !empty($marketData['market_breadth'])) {
            $sources[] = 'Market Breadth Indicators';
        }

        return $sources;
    }
}
