<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Market Data Service
 * Integrates FREE public APIs for improved trading signal accuracy
 * NO API KEYS REQUIRED
 */
class EnhancedMarketDataService
{
    /**
     * General data quality checks for market data
     * @param array $marketData
     * @return array
     */
    public function assessDataQuality(array $marketData): array
    {
        $quality = [
            'completeness' => !empty($marketData['current_price']) && !empty($marketData['volume']) && !empty($marketData['volatility']),
            'recency' => isset($marketData['timestamp']) ? (time() - strtotime($marketData['timestamp']) < 3600) : false,
            'consistency' => true,
            'reliability_score' => 0
        ];
        // Consistency: check if price, volume, volatility are not outliers
        if (isset($marketData['current_price']['price'], $marketData['volume']['volume_24h'], $marketData['volatility']['volatility_7d'])) {
            $price = $marketData['current_price']['price'];
            $volume = $marketData['volume']['volume_24h'];
            $vol = $marketData['volatility']['volatility_7d'];
            $quality['consistency'] = ($price > 0 && $volume > 0 && $vol >= 0 && $vol < 100);
        }
        // Reliability score: sum of checks
        $quality['reliability_score'] = ($quality['completeness'] + $quality['recency'] + $quality['consistency']) / 3 * 100;
        return $quality;
    }
    /**
     * Aggregate signals using robust ensemble methods
     * @param array $signalSets Array of arrays: [['technical'=>..., 'sentiment'=>..., ...], ...]
     * @return array
     */
    public function aggregateEnsembleSignals(array $signalSets): array
    {
        $ensemble = [
            'technical' => [],
            'sentiment' => [],
            'overall' => [],
        ];
        foreach ($signalSets as $signals) {
            foreach (['technical', 'sentiment', 'overall'] as $key) {
                if (isset($signals[$key])) $ensemble[$key][] = $signals[$key];
            }
        }
        // Weighted average (robust to outliers)
        $result = [];
        foreach ($ensemble as $key => $values) {
            if (empty($values)) {
                $result[$key] = null;
                continue;
            }
            // Remove outliers (outside 1.5*IQR)
            sort($values);
            $q1 = $values[(int)(0.25 * count($values))];
            $q3 = $values[(int)(0.75 * count($values))];
            $iqr = $q3 - $q1;
            $filtered = array_filter($values, function($v) use ($q1, $q3, $iqr) {
                return $v >= ($q1 - 1.5 * $iqr) && $v <= ($q3 + 1.5 * $iqr);
            });
            $result[$key] = count($filtered) ? array_sum($filtered) / count($filtered) : array_sum($values) / count($values);
        }
        // Majority voting for categorical signals (if present)
        if (isset($signalSets[0]['signal'])) {
            $votes = [];
            foreach ($signalSets as $signals) {
                if (isset($signals['signal'])) {
                    $votes[] = $signals['signal'];
                }
            }
            $counts = array_count_values($votes);
            arsort($counts);
            $result['majority_signal'] = key($counts);
        }
        return $result;
    }
    // Free Public APIs - No authentication required
    protected $wallstreetBetsUrl;    // Social Sentiment Analysis
    protected $exchangeRateUrl;      // Currency Exchange Rates
    protected $frankfurterUrl;       // Alternative Exchange Rates
    protected $coinGeckoUrl;         // Cryptocurrency Data
    protected $binanceUrl;           // Binance Crypto Data
    protected $coinpaprikaUrl;       // Additional Crypto Data
    protected $coincapUrl;           // Real-time Crypto Prices

    public function __construct()
    {
        // All free public APIs - no keys needed
        $this->wallstreetBetsUrl = 'https://dashboard.nbshare.io/apps/reddit/api/';
        $this->exchangeRateUrl = 'https://api.exchangerate.host';
        $this->frankfurterUrl = 'https://api.frankfurter.app';
        $this->coinGeckoUrl = 'https://api.coingecko.com/api/v3';
        $this->binanceUrl = 'https://api.binance.com/api/v3';
        $this->coinpaprikaUrl = 'https://api.coinpaprika.com/v1';
        $this->coincapUrl = 'https://api.coincap.io/v2';
    }

    /**
     * Get comprehensive market data from FREE public APIs
     * NO API KEYS REQUIRED
     */
    public function getComprehensiveMarketData(string $symbol, string $market = 'stocks'): array
    {
        $cacheKey = "free_market_{$market}_{$symbol}_" . date('Y-m-d-H');
        
        return Cache::remember($cacheKey, 1800, function () use ($symbol, $market) {
            $data = [];

            try {
                // Market Data
                if ($market === 'crypto') {
                    $data['crypto_data'] = $this->getFreeCryptoData($symbol);
                    $data['crypto_sentiment'] = $this->getCryptoSentiment($symbol);
                }
                
                // Social Sentiment (WallStreetBets for stocks)
                if ($market === 'stocks') {
                    $data['social_sentiment'] = $this->getWallStreetBetsSentiment($symbol);
                }
                
                // Currency Exchange Rates
                $data['exchange_rates'] = $this->getFreeExchangeRates();
                
            } catch (\Exception $e) {
                Log::error("Free Market Data Error: " . $e->getMessage());
                $data['error'] = 'Some data sources unavailable';
            }

            return $data;
        });
    }

    /**
     * Get FREE cryptocurrency data from multiple sources
     * Uses: CoinGecko, Binance, Coinpaprika, CoinCap
     */
    protected function getFreeCryptoData(string $symbol): array
    {
        $cryptoData = [];
        
        // Normalize symbol for CoinGecko (e.g., BTC -> bitcoin)
        $coinGeckoId = $this->mapCryptoSymbolToCoinGecko($symbol);

        // CoinGecko - Comprehensive crypto data (100% FREE)
        try {
            $response = Http::timeout(5)->get("{$this->coinGeckoUrl}/coins/{$coinGeckoId}", [
                'localization' => 'false',
                'tickers' => 'false',
                'community_data' => 'true',
                'developer_data' => 'false'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $cryptoData['coingecko'] = [
                    'price' => $data['market_data']['current_price']['usd'] ?? null,
                    'market_cap' => $data['market_data']['market_cap']['usd'] ?? null,
                    'volume_24h' => $data['market_data']['total_volume']['usd'] ?? null,
                    'price_change_24h' => $data['market_data']['price_change_percentage_24h'] ?? null,
                    'price_change_7d' => $data['market_data']['price_change_percentage_7d'] ?? null,
                    'price_change_30d' => $data['market_data']['price_change_percentage_30d'] ?? null,
                    'ath' => $data['market_data']['ath']['usd'] ?? null,
                    'atl' => $data['market_data']['atl']['usd'] ?? null,
                    'sentiment_votes_up_percentage' => $data['sentiment_votes_up_percentage'] ?? null,
                    'sentiment_votes_down_percentage' => $data['sentiment_votes_down_percentage'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("CoinGecko Error: " . $e->getMessage());
        }

        // Binance - Real-time crypto trading data (100% FREE Public API)
        try {
            $binanceSymbol = strtoupper($symbol) . 'USDT';
            $response = Http::timeout(5)->get("{$this->binanceUrl}/ticker/24hr", [
                'symbol' => $binanceSymbol
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $cryptoData['binance'] = [
                    'price' => $data['lastPrice'] ?? null,
                    'volume' => $data['volume'] ?? null,
                    'quote_volume' => $data['quoteVolume'] ?? null,
                    'price_change' => $data['priceChange'] ?? null,
                    'price_change_percent' => $data['priceChangePercent'] ?? null,
                    'high_24h' => $data['highPrice'] ?? null,
                    'low_24h' => $data['lowPrice'] ?? null,
                    'trades_count' => $data['count'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Binance Error: " . $e->getMessage());
        }

        // Coinpaprika - Additional crypto data (100% FREE)
        try {
            $coinpaprikaId = strtolower($symbol) . '-' . $this->mapCryptoSymbolToCoinGecko($symbol);
            $response = Http::timeout(5)->get("{$this->coinpaprikaUrl}/tickers/{$coinpaprikaId}");

            if ($response->successful()) {
                $data = $response->json();
                $cryptoData['coinpaprika'] = [
                    'price' => $data['quotes']['USD']['price'] ?? null,
                    'volume_24h' => $data['quotes']['USD']['volume_24h'] ?? null,
                    'market_cap' => $data['quotes']['USD']['market_cap'] ?? null,
                    'percent_change_24h' => $data['quotes']['USD']['percent_change_24h'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Coinpaprika Error: " . $e->getMessage());
        }

        // CoinCap - Real-time prices (100% FREE)
        try {
            $response = Http::timeout(5)->get("{$this->coincapUrl}/assets/" . strtolower($symbol));

            if ($response->successful()) {
                $data = $response->json()['data'] ?? null;
                if ($data) {
                    $cryptoData['coincap'] = [
                        'price' => $data['priceUsd'] ?? null,
                        'market_cap' => $data['marketCapUsd'] ?? null,
                        'volume_24h' => $data['volumeUsd24Hr'] ?? null,
                        'change_24h' => $data['changePercent24Hr'] ?? null,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("CoinCap Error: " . $e->getMessage());
        }

        return $cryptoData;
    }

    /**
     * Get crypto sentiment from CoinGecko (100% FREE)
     */
    protected function getCryptoSentiment(string $symbol): array
    {
        // Use CoinGecko community data
        $coinGeckoId = $this->mapCryptoSymbolToCoinGecko($symbol);
        
        try {
            $response = Http::timeout(5)->get("{$this->coinGeckoUrl}/coins/{$coinGeckoId}", [
                'localization' => 'false',
                'community_data' => 'true'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'sentiment_votes_up' => $data['sentiment_votes_up_percentage'] ?? 0,
                    'sentiment_votes_down' => $data['sentiment_votes_down_percentage'] ?? 0,
                    'community_score' => $data['community_score'] ?? 0,
                    'developer_score' => $data['developer_score'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Crypto Sentiment Error: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get WallStreetBets sentiment for stocks (100% FREE)
     */
    protected function getWallStreetBetsSentiment(string $symbol): array
    {
        try {
            $response = Http::timeout(10)->get($this->wallstreetBetsUrl . 'wsb', [
                'symbol' => $symbol
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'mentions' => $data['mentions'] ?? 0,
                    'sentiment' => $data['sentiment'] ?? 'neutral',
                    'score' => $data['score'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("WallStreetBets API Error: " . $e->getMessage());
        }

        return ['status' => 'unavailable'];
    }

    /**
     * Get current exchange rates from FREE APIs
     * Uses: ExchangeRate.host and Frankfurter (100% FREE)
     */
    protected function getFreeExchangeRates(): array
    {
        try {
            // Using free exchangerate.host API (100% FREE)
            $response = Http::timeout(5)->get("{$this->exchangeRateUrl}/latest", [
                'base' => 'USD'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $rates = [
                    'source' => 'exchangerate.host',
                    'base' => $data['base'] ?? 'USD',
                    'date' => $data['date'] ?? null,
                    'rates' => [
                        'EUR' => $data['rates']['EUR'] ?? null,
                        'GBP' => $data['rates']['GBP'] ?? null,
                        'JPY' => $data['rates']['JPY'] ?? null,
                        'CNY' => $data['rates']['CNY'] ?? null,
                        'CHF' => $data['rates']['CHF'] ?? null,
                    ]
                ];
                
                return $rates;
            }
        } catch (\Exception $e) {
            Log::warning("Exchange Rate Error: " . $e->getMessage());
        }

        // Fallback to Frankfurter API (100% FREE)
        try {
            $response = Http::timeout(5)->get("{$this->frankfurterUrl}/latest", [
                'from' => 'USD'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'source' => 'frankfurter',
                    'base' => $data['base'] ?? 'USD',
                    'date' => $data['date'] ?? null,
                    'rates' => $data['rates'] ?? []
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Frankfurter Error: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Map crypto symbols to CoinGecko IDs
     */
    protected function mapCryptoSymbolToCoinGecko(string $symbol): string
    {
        $mapping = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'USDT' => 'tether',
            'BNB' => 'binancecoin',
            'XRP' => 'ripple',
            'ADA' => 'cardano',
            'DOGE' => 'dogecoin',
            'SOL' => 'solana',
            'DOT' => 'polkadot',
            'MATIC' => 'matic-network',
            'LTC' => 'litecoin',
            'AVAX' => 'avalanche-2',
            'LINK' => 'chainlink',
            'UNI' => 'uniswap',
            'ATOM' => 'cosmos',
        ];

        return $mapping[strtoupper($symbol)] ?? strtolower($symbol);
    }

    /**
     * Get aggregated signal strength from FREE data sources only
     */
    public function calculateAggregatedSignalStrength(array $marketData): array
    {
        $signals = [
            'technical' => 0,
            'sentiment' => 0,
            'overall' => 0,
            'confidence' => 0,
        ];

        // Technical signals (from crypto price action)
        if (isset($marketData['crypto_data'])) {
            $signals['technical'] = $this->calculateTechnicalSignal($marketData);
        }

        // Sentiment signals (from social + crypto sentiment)
        if (isset($marketData['social_sentiment']) || isset($marketData['crypto_sentiment'])) {
            $signals['sentiment'] = $this->calculateSentimentSignal($marketData);
        }

        // Dynamic weighting based on data quality and volatility
        $confidence = ($dataSourcesAvailable / $totalSources);
        $volatility = $marketData['volatility']['volatility_7d'] ?? 10;
        // If confidence is high and volatility is low, favor technicals
        if ($confidence > 0.8 && $volatility < 15) {
            $weights = [ 'technical' => 0.7, 'sentiment' => 0.3 ];
        } elseif ($confidence < 0.5 || $volatility > 25) {
            // If confidence is low or volatility is high, favor sentiment
            $weights = [ 'technical' => 0.3, 'sentiment' => 0.7 ];
        } else {
            // Otherwise, balanced
            $weights = [ 'technical' => 0.5, 'sentiment' => 0.5 ];
        }
        $signals['overall'] = (
            $signals['technical'] * $weights['technical'] +
            $signals['sentiment'] * $weights['sentiment']
        );

        // Calculate confidence based on data availability
        $dataSourcesAvailable = 0;
        $totalSources = 3;
        
        if (isset($marketData['crypto_data'])) $dataSourcesAvailable++;
        if (isset($marketData['social_sentiment'])) $dataSourcesAvailable++;
        if (isset($marketData['crypto_sentiment'])) $dataSourcesAvailable++;

        $signals['confidence'] = ($dataSourcesAvailable / $totalSources) * 100;

        return $signals;
    }

    protected function calculateTechnicalSignal(array $data): float
    {
        // Simplified technical signal calculation
        // In practice, this would analyze price trends, volume, etc.
        return 0.5; // Neutral by default
    }

    protected function calculateSentimentSignal(array $marketData): float
    {
        $sentiment = 0;
        $count = 0;
        
        // Social sentiment from WallStreetBets
        if (isset($marketData['social_sentiment']['score'])) {
            $sentiment += $marketData['social_sentiment']['score'];
            $count++;
        }
        
        // Crypto sentiment from CoinGecko
        if (isset($marketData['crypto_sentiment']['sentiment_votes_up'])) {
            $upPercent = $marketData['crypto_sentiment']['sentiment_votes_up'];
            $sentiment += ($upPercent / 100) * 2 - 1; // Convert to -1 to 1 scale
            $count++;
        }
        
        return $count > 0 ? $sentiment / $count : 0;
    }
}
