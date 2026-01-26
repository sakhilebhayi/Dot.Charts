<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    protected $alphaVantageKey;
    protected $polygonKey;
    protected $finnhubKey;

    public function __construct()
    {
        $this->alphaVantageKey = config('services.alphavantage.api_key');
        $this->polygonKey = config('services.polygon.api_key');
        $this->finnhubKey = config('services.finnhub.api_key');
    }

    /**
     * Gather comprehensive market data for analysis
     */
    public function gatherMarketData(string $symbol = 'BTC', string $market = 'crypto'): array
    {
        $cacheKey = "market_data_{$market}_{$symbol}_" . date('Y-m-d-H');
        
        return Cache::remember($cacheKey, 1800, function () use ($symbol, $market) {
            try {
                $data = [];

                // Get current price and basic info
                $data['current_price'] = $this->getCurrentPrice($symbol, $market);
                
                // Get volume data
                $data['volume'] = $this->getVolumeData($symbol, $market);
                
                // Get market sentiment indicators
                $data['sentiment'] = $this->getMarketSentiment($symbol, $market);
                
                // Get historical volatility
                $data['volatility'] = $this->getVolatilityMetrics($symbol, $market);
                
                // Get order book depth (for crypto)
                if ($market === 'crypto') {
                    $data['order_book'] = $this->getOrderBookDepth($symbol);
                }
                
                // Get moving averages and technical indicators
                $data['technical_indicators'] = $this->getTechnicalIndicators($symbol, $market);
                
                // Get market cap and rank
                $data['market_cap'] = $this->getMarketCapData($symbol, $market);
                
                return $data;
            } catch (\Exception $e) {
                Log::error('Market Data Gathering Failed: ' . $e->getMessage());
                return $this->getDefaultMarketData();
            }
        });
    }

    /**
     * Get current price from multiple sources
     */
    protected function getCurrentPrice(string $symbol, string $market): array
    {
        try {
            if ($market === 'crypto') {
                return $this->getCryptoPrice($symbol);
            } elseif ($market === 'stock') {
                return $this->getStockPrice($symbol);
            } elseif ($market === 'forex') {
                return $this->getForexPrice($symbol);
            }
        } catch (\Exception $e) {
            Log::error("Failed to get current price: " . $e->getMessage());
        }

        return ['price' => 0, 'change_24h' => 0, 'change_percent' => 0];
    }

    protected function getCryptoPrice(string $symbol): array
    {
        // Try CoinGecko (free API)
        try {
            $response = Http::timeout(10)->get("https://api.coingecko.com/api/v3/simple/price", [
                'ids' => strtolower($symbol),
                'vs_currencies' => 'usd',
                'include_24hr_change' => 'true',
                'include_24hr_vol' => 'true'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $symbolLower = strtolower($symbol);
                
                if (isset($data[$symbolLower])) {
                    return [
                        'price' => $data[$symbolLower]['usd'] ?? 0,
                        'change_24h' => $data[$symbolLower]['usd_24h_change'] ?? 0,
                        'volume_24h' => $data[$symbolLower]['usd_24h_vol'] ?? 0
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("CoinGecko API error: " . $e->getMessage());
        }

        // Fallback to Binance API
        try {
            $response = Http::timeout(10)->get("https://api.binance.com/api/v3/ticker/24hr", [
                'symbol' => strtoupper($symbol) . 'USDT'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'price' => (float) $data['lastPrice'],
                    'change_24h' => (float) $data['priceChangePercent'],
                    'volume_24h' => (float) $data['volume']
                ];
            }
        } catch (\Exception $e) {
            Log::error("Binance API error: " . $e->getMessage());
        }

        return ['price' => 0, 'change_24h' => 0, 'volume_24h' => 0];
    }

    protected function getStockPrice(string $symbol): array
    {
        if (empty($this->finnhubKey)) {
            return ['price' => 0, 'change' => 0, 'change_percent' => 0];
        }

        try {
            $response = Http::timeout(10)->get("https://finnhub.io/api/v1/quote", [
                'symbol' => strtoupper($symbol),
                'token' => $this->finnhubKey
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'price' => $data['c'] ?? 0, // current price
                    'change' => $data['d'] ?? 0, // change
                    'change_percent' => $data['dp'] ?? 0, // change percent
                    'high' => $data['h'] ?? 0,
                    'low' => $data['l'] ?? 0,
                    'open' => $data['o'] ?? 0,
                    'previous_close' => $data['pc'] ?? 0
                ];
            }
        } catch (\Exception $e) {
            Log::error("Finnhub API error: " . $e->getMessage());
        }

        return ['price' => 0, 'change' => 0, 'change_percent' => 0];
    }

    protected function getForexPrice(string $symbol): array
    {
        if (empty($this->alphaVantageKey)) {
            return ['price' => 0, 'change' => 0];
        }

        try {
            $response = Http::timeout(10)->get("https://www.alphavantage.co/query", [
                'function' => 'CURRENCY_EXCHANGE_RATE',
                'from_currency' => substr($symbol, 0, 3),
                'to_currency' => substr($symbol, 3, 3),
                'apikey' => $this->alphaVantageKey
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $rate = $data['Realtime Currency Exchange Rate'] ?? [];
                
                return [
                    'price' => (float) ($rate['5. Exchange Rate'] ?? 0),
                    'bid' => (float) ($rate['8. Bid Price'] ?? 0),
                    'ask' => (float) ($rate['9. Ask Price'] ?? 0)
                ];
            }
        } catch (\Exception $e) {
            Log::error("AlphaVantage API error: " . $e->getMessage());
        }

        return ['price' => 0, 'change' => 0];
    }

    protected function getVolumeData(string $symbol, string $market): array
    {
        // Volume data is often included in price calls
        // This is a placeholder for more detailed volume analysis
        return [
            'volume_24h' => 0,
            'volume_7d' => 0,
            'volume_change_24h' => 0
        ];
    }

    protected function getMarketSentiment(string $symbol, string $market): array
    {
        try {
            // Use Fear & Greed Index for crypto
            if ($market === 'crypto') {
                $response = Http::timeout(10)->get("https://api.alternative.me/fng/");
                
                if ($response->successful()) {
                    $data = $response->json();
                    $fng = $data['data'][0] ?? [];
                    
                    return [
                        'fear_greed_index' => (int) ($fng['value'] ?? 50),
                        'sentiment' => $fng['value_classification'] ?? 'Neutral'
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("Sentiment API error: " . $e->getMessage());
        }

        return [
            'fear_greed_index' => 50,
            'sentiment' => 'Neutral'
        ];
    }

    protected function getVolatilityMetrics(string $symbol, string $market): array
    {
        // Calculate basic volatility from historical data
        return [
            'volatility_7d' => 0,
            'volatility_30d' => 0,
            'atr' => 0 // Average True Range
        ];
    }

    protected function getOrderBookDepth(string $symbol): array
    {
        try {
            // Get order book from Binance
            $response = Http::timeout(10)->get("https://api.binance.com/api/v3/depth", [
                'symbol' => strtoupper($symbol) . 'USDT',
                'limit' => 100
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'bid_depth' => count($data['bids'] ?? []),
                    'ask_depth' => count($data['asks'] ?? []),
                    'spread' => $this->calculateSpread($data)
                ];
            }
        } catch (\Exception $e) {
            Log::error("Order book API error: " . $e->getMessage());
        }

        return ['bid_depth' => 0, 'ask_depth' => 0, 'spread' => 0];
    }

    protected function calculateSpread(array $orderBook): float
    {
        $bestBid = (float) ($orderBook['bids'][0][0] ?? 0);
        $bestAsk = (float) ($orderBook['asks'][0][0] ?? 0);
        
        if ($bestBid > 0 && $bestAsk > 0) {
            return round((($bestAsk - $bestBid) / $bestBid) * 100, 4);
        }
        
        return 0;
    }

    protected function getTechnicalIndicators(string $symbol, string $market): array
    {
        // This would typically call APIs for RSI, MACD, Moving Averages, etc.
        // For now, return placeholder structure
        return [
            'rsi_14' => 50,
            'macd' => ['macd' => 0, 'signal' => 0, 'histogram' => 0],
            'moving_averages' => [
                'sma_50' => 0,
                'sma_200' => 0,
                'ema_20' => 0
            ],
            'bollinger_bands' => [
                'upper' => 0,
                'middle' => 0,
                'lower' => 0
            ]
        ];
    }

    protected function getMarketCapData(string $symbol, string $market): array
    {
        if ($market === 'crypto') {
            try {
                $response = Http::timeout(10)->get("https://api.coingecko.com/api/v3/coins/{$symbol}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    $marketData = $data['market_data'] ?? [];
                    
                    return [
                        'market_cap' => $marketData['market_cap']['usd'] ?? 0,
                        'market_cap_rank' => $data['market_cap_rank'] ?? 0,
                        'total_volume' => $marketData['total_volume']['usd'] ?? 0,
                        'circulating_supply' => $marketData['circulating_supply'] ?? 0
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Market cap API error: " . $e->getMessage());
            }
        }

        return [
            'market_cap' => 0,
            'market_cap_rank' => 0,
            'total_volume' => 0
        ];
    }

    protected function getDefaultMarketData(): array
    {
        return [
            'current_price' => ['price' => 0, 'change_24h' => 0],
            'volume' => ['volume_24h' => 0],
            'sentiment' => ['sentiment' => 'Neutral', 'fear_greed_index' => 50],
            'volatility' => ['volatility_7d' => 0],
            'technical_indicators' => [
                'rsi_14' => 50,
                'macd' => ['macd' => 0, 'signal' => 0],
                'moving_averages' => ['sma_50' => 0, 'sma_200' => 0]
            ],
            'market_cap' => ['market_cap' => 0, 'market_cap_rank' => 0]
        ];
    }
}
