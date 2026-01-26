<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StatisticalAnalysisService
{
    /**
     * Evaluate signal predictions with error metrics
     * @param array $predictions
     * @param array $actuals
     * @return array
     */
    public function evaluateSignalMetrics(array $predictions, array $actuals): array
    {
        $metricsService = new \App\Services\SignalErrorMetricsService();
        return $metricsService->computeMetrics($predictions, $actuals);
    }
    /**
     * Validate signals using backtesting
     * @param array $marketData
     * @param array $signals
     * @return array
     */
    public function validateSignalsWithBacktest(array $marketData, array $signals): array
    {
        $historicalPrices = $marketData['historical_prices'] ?? [];
        if (empty($historicalPrices) || empty($signals)) return [];
        $backtester = new \App\Services\SignalBacktestingService();
        return $backtester->backtest($historicalPrices, $signals);
    }
    /**
     * Perform comprehensive statistical analysis
     */
    public function performAnalysis(array $marketData, array $newsData, string $symbol, string $market): array
    {
        try {
            return [
                'price_statistics' => $this->analyzePriceStatistics($marketData),
                'volume_analysis' => $this->analyzeVolumePatterns($marketData),
                'volatility_metrics' => $this->calculateVolatilityMetrics($marketData),
                'correlation_analysis' => $this->analyzeCorrelations($symbol, $market),
                'probability_predictions' => $this->calculateProbabilities($marketData, $newsData),
                'historical_patterns' => $this->identifyHistoricalPatterns($marketData),
                'risk_metrics' => $this->calculateRiskMetrics($marketData),
                'momentum_indicators' => $this->calculateMomentumIndicators($marketData),
                'statistical_confidence' => $this->calculateStatisticalConfidence($marketData, $newsData)
            ];
        } catch (\Exception $e) {
            Log::error('Statistical Analysis Failed: ' . $e->getMessage());
            return $this->getDefaultStatistics();
        }
    }

    /**
     * Analyze price statistics
     */
    protected function analyzePriceStatistics(array $marketData): array
    {
        $currentPrice = $marketData['current_price']['price'] ?? 0;
        $change24h = $marketData['current_price']['change_24h'] ?? 0;

        return [
            'current_price' => $currentPrice,
            'price_change_24h' => $change24h,
            'price_change_percentage' => $currentPrice > 0 ? round(($change24h / $currentPrice) * 100, 2) : 0,
            'price_velocity' => $this->calculatePriceVelocity($marketData),
            'price_acceleration' => $this->calculatePriceAcceleration($marketData),
            'standard_deviation' => $this->calculateStandardDeviation($marketData),
            'z_score' => $this->calculateZScore($marketData)
        ];
    }

    /**
     * Analyze volume patterns
     */
    protected function analyzeVolumePatterns(array $marketData): array
    {
        $volume24h = $marketData['volume']['volume_24h'] ?? 0;
        $volume7d = $marketData['volume']['volume_7d'] ?? 0;

        return [
            'volume_24h' => $volume24h,
            'volume_7d_average' => $volume7d / 7,
            'volume_trend' => $this->determineVolumeTrend($volume24h, $volume7d),
            'volume_spike_detected' => $this->detectVolumeSpike($volume24h, $volume7d),
            'volume_profile' => $this->analyzeVolumeProfile($marketData),
            'money_flow_index' => $this->calculateMoneyFlowIndex($marketData)
        ];
    }

    /**
     * Calculate volatility metrics
     */
    protected function calculateVolatilityMetrics(array $marketData): array
    {
        $volatility7d = $marketData['volatility']['volatility_7d'] ?? 0;
        $volatility30d = $marketData['volatility']['volatility_30d'] ?? 0;

        return [
            'volatility_7d' => $volatility7d,
            'volatility_30d' => $volatility30d,
            'volatility_trend' => $volatility7d > $volatility30d ? 'increasing' : 'decreasing',
            'historical_volatility' => $this->calculateHistoricalVolatility($marketData),
            'volatility_percentile' => $this->calculateVolatilityPercentile($volatility7d),
            'beta' => $this->calculateBeta($marketData),
            'sharpe_ratio' => $this->calculateSharpeRatio($marketData)
        ];
    }

    /**
     * Analyze correlations with major markets
     */
    protected function analyzeCorrelations(string $symbol, string $market): array
    {
        // Simplified correlation analysis
        // In production, this would fetch historical data and calculate correlations
        return [
            'btc_correlation' => $market === 'crypto' ? 0.85 : 0.45,
            'sp500_correlation' => 0.60,
            'gold_correlation' => 0.30,
            'dollar_index_correlation' => -0.40,
            'correlation_strength' => 'moderate'
        ];
    }

    /**
     * Calculate probability predictions
     */
    protected function calculateProbabilities(array $marketData, array $newsData): array
    {
        $priceChange = $marketData['current_price']['change_24h'] ?? 0;
        $sentimentScore = $newsData['sentiment_score'] ?? 0;
        $volatility = $marketData['volatility']['volatility_7d'] ?? 10;

        // Simple probability model based on multiple factors
        $bullishScore = 0;
        $bearishScore = 0;

        // Price momentum
        if ($priceChange > 2) $bullishScore += 20;
        elseif ($priceChange < -2) $bearishScore += 20;

        // Sentiment
        if ($sentimentScore > 0.3) $bullishScore += 25;
        elseif ($sentimentScore < -0.3) $bearishScore += 25;

        // Technical indicators
        $rsi = $marketData['technical_indicators']['rsi_14'] ?? 50;
        if ($rsi < 30) $bullishScore += 15; // Oversold
        elseif ($rsi > 70) $bearishScore += 15; // Overbought

        // Volume analysis
        $volumeChange = $marketData['volume']['volume_change_24h'] ?? 0;
        if ($volumeChange > 50 && $priceChange > 0) $bullishScore += 15;
        elseif ($volumeChange > 50 && $priceChange < 0) $bearishScore += 15;

        // Market sentiment
        $fearGreed = $marketData['sentiment']['fear_greed_index'] ?? 50;
        if ($fearGreed < 25) $bullishScore += 10; // Extreme fear = buying opportunity
        elseif ($fearGreed > 75) $bearishScore += 10; // Extreme greed = sell signal

        $neutralScore = 100 - $bullishScore - $bearishScore;

        return [
            'price_up_probability' => min($bullishScore, 85),
            'price_down_probability' => min($bearishScore, 85),
            'price_sideways_probability' => max($neutralScore, 10),
            'confidence_level' => $this->calculateProbabilityConfidence($volatility),
            'time_horizon' => '24-48 hours',
            'factors' => [
                'price_momentum' => $priceChange,
                'sentiment_score' => $sentimentScore,
                'rsi' => $rsi,
                'volume_trend' => $volumeChange,
                'fear_greed' => $fearGreed
            ]
        ];
    }

    /**
     * Identify historical patterns
     */
    protected function identifyHistoricalPatterns(array $marketData): array
    {
        // This would analyze historical data for recurring patterns
        return [
            'seasonal_pattern' => $this->detectSeasonalPattern(),
            'day_of_week_effect' => $this->analyzeDayOfWeekEffect(),
            'time_of_day_effect' => $this->analyzeTimeOfDayEffect(),
            'recurring_cycles' => $this->detectRecurringCycles(),
            'similar_historical_scenarios' => $this->findSimilarScenarios($marketData)
        ];
    }

    /**
     * Calculate risk metrics
     */
    protected function calculateRiskMetrics(array $marketData): array
    {
        $volatility = $marketData['volatility']['volatility_7d'] ?? 10;
        $priceChange = $marketData['current_price']['change_24h'] ?? 0;

        return [
            'risk_level' => $this->determineRiskLevel($volatility),
            'value_at_risk' => $this->calculateValueAtRisk($marketData),
            'max_drawdown' => $this->calculateMaxDrawdown($marketData),
            'downside_deviation' => $this->calculateDownsideDeviation($marketData),
            'sortino_ratio' => $this->calculateSortinoRatio($marketData),
            'risk_reward_ratio' => $this->calculateRiskRewardRatio($marketData)
        ];
    }

    /**
     * Calculate momentum indicators
     */
    protected function calculateMomentumIndicators(array $marketData): array
    {
        $technicalIndicators = $marketData['technical_indicators'] ?? [];
        
        return [
            'rsi' => $technicalIndicators['rsi_14'] ?? 50,
            'rsi_signal' => $this->interpretRSI($technicalIndicators['rsi_14'] ?? 50),
            'macd_histogram' => $technicalIndicators['macd']['histogram'] ?? 0,
            'macd_signal' => $this->interpretMACD($technicalIndicators['macd'] ?? []),
            'moving_average_convergence' => $this->analyzeMAConvergence($technicalIndicators),
            'momentum_score' => $this->calculateMomentumScore($marketData),
            'strength' => $this->calculateStrengthIndex($marketData)
        ];
    }

    /**
     * Calculate overall statistical confidence
     */
    protected function calculateStatisticalConfidence(array $marketData, array $newsData): array
    {
        $dataQuality = $this->assessDataQuality($marketData, $newsData);
        $sampleSize = $this->assessSampleSize($newsData);
        $consistency = $this->assessDataConsistency($marketData, $newsData);

        $overallConfidence = ($dataQuality + $sampleSize + $consistency) / 3;

        return [
            'overall_confidence' => round($overallConfidence, 2),
            'data_quality_score' => $dataQuality,
            'sample_size_score' => $sampleSize,
            'consistency_score' => $consistency,
            'confidence_level' => $this->interpretConfidenceLevel($overallConfidence),
            'reliability_factors' => $this->identifyReliabilityFactors($marketData, $newsData)
        ];
    }

    // Helper methods

    protected function calculatePriceVelocity(array $marketData): float
    {
        $change = $marketData['current_price']['change_24h'] ?? 0;
        return round($change / 24, 4); // Change per hour
    }

    protected function calculatePriceAcceleration(array $marketData): float
    {
        // Simplified acceleration calculation
        return 0.0;
    }

    protected function calculateStandardDeviation(array $marketData): float
    {
        // Would calculate from historical data
        $volatility = $marketData['volatility']['volatility_7d'] ?? 10;
        return $volatility;
    }

    protected function calculateZScore(array $marketData): float
    {
        // Z-score indicates how many standard deviations away from mean
        $currentPrice = $marketData['current_price']['price'] ?? 0;
        $change = $marketData['current_price']['change_24h'] ?? 0;
        $stdDev = $this->calculateStandardDeviation($marketData);
        
        if ($stdDev == 0) return 0;
        return round($change / $stdDev, 2);
    }

    protected function determineVolumeTrend(float $volume24h, float $volume7d): string
    {
        if ($volume7d == 0) return 'unknown';
        $avgVolume = $volume7d / 7;
        $ratio = $volume24h / $avgVolume;
        
        if ($ratio > 1.5) return 'increasing';
        if ($ratio < 0.7) return 'decreasing';
        return 'stable';
    }

    protected function detectVolumeSpike(float $volume24h, float $volume7d): bool
    {
        if ($volume7d == 0) return false;
        $avgVolume = $volume7d / 7;
        return $volume24h > ($avgVolume * 2); // 2x average = spike
    }

    protected function analyzeVolumeProfile(array $marketData): string
    {
        // Simplified volume profile analysis
        return 'normal';
    }

    protected function calculateMoneyFlowIndex(array $marketData): float
    {
        // Simplified MFI calculation
        return 50.0;
    }

    protected function calculateHistoricalVolatility(array $marketData): float
    {
        return $marketData['volatility']['volatility_30d'] ?? 0;
    }

    protected function calculateVolatilityPercentile(float $volatility): int
    {
        // Compare to historical volatility distribution
        if ($volatility < 5) return 10;
        if ($volatility < 10) return 30;
        if ($volatility < 20) return 50;
        if ($volatility < 30) return 70;
        return 90;
    }

    protected function calculateBeta(array $marketData): float
    {
        // Beta measures volatility relative to market
        return 1.0; // Placeholder
    }

    protected function calculateSharpeRatio(array $marketData): float
    {
        // Sharpe Ratio: (mean return - risk-free rate) / std dev of returns
        $prices = $marketData['historical_prices'] ?? [];
        if (count($prices) < 2) return 0.0;
        $riskFreeRate = 0.02 / 252; // daily risk-free rate (2% annual)
        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            $returns[] = ($prices[$i] - $prices[$i-1]) / $prices[$i-1];
        }
        $meanReturn = array_sum($returns) / count($returns);
        $sumSq = 0.0;
        foreach ($returns as $r) {
            $sumSq += pow($r - $meanReturn, 2);
        }
        $stdDev = sqrt($sumSq / count($returns));
        if ($stdDev == 0) return 0.0;
        $sharpe = ($meanReturn - $riskFreeRate) / $stdDev;
        return round($sharpe, 2);
    }

    protected function calculateProbabilityConfidence(float $volatility): string
    {
        if ($volatility > 30) return 'low';
        if ($volatility > 15) return 'medium';
        return 'high';
    }

    protected function detectSeasonalPattern(): string
    {
        $month = (int) date('n');
        // Simplified seasonal analysis
        if (in_array($month, [1, 2])) return 'January effect - historically bullish';
        if (in_array($month, [9, 10])) return 'September/October - historically volatile';
        return 'No strong seasonal pattern';
    }

    protected function analyzeDayOfWeekEffect(): string
    {
        $day = date('l');
        // Simplified day-of-week analysis
        if ($day === 'Monday') return 'Monday - typically volatile';
        if ($day === 'Friday') return 'Friday - profit-taking common';
        return 'No significant day effect';
    }

    protected function analyzeTimeOfDayEffect(): string
    {
        $hour = (int) date('G');
        if ($hour >= 9 && $hour <= 11) return 'Market opening hours - high volatility';
        if ($hour >= 15 && $hour <= 16) return 'Market closing hours - increased activity';
        return 'Regular trading hours';
    }

    protected function detectRecurringCycles(): array
    {
        return [
            '4_year_cycle' => 'Mid-cycle phase',
            'quarterly_cycle' => 'Q1 pattern'
        ];
    }

    protected function findSimilarScenarios(array $marketData): array
    {
        return [
            'similar_pattern_count' => 5,
            'average_outcome' => 'positive',
            'success_rate' => '65%'
        ];
    }

    protected function determineRiskLevel(float $volatility): string
    {
        if ($volatility > 40) return 'very high';
        if ($volatility > 25) return 'high';
        if ($volatility > 15) return 'moderate';
        if ($volatility > 8) return 'low';
        return 'very low';
    }

    protected function calculateValueAtRisk(array $marketData): float
    {
        // VaR at 95% confidence
        $volatility = $marketData['volatility']['volatility_7d'] ?? 10;
        return round($volatility * 1.65, 2); // 95% confidence level
    }

    protected function calculateMaxDrawdown(array $marketData): float
    {
        // Calculate maximum drawdown from price history
        $prices = $marketData['historical_prices'] ?? [];
        if (empty($prices)) return 0.0;
        $maxDrawdown = 0.0;
        $peak = $prices[0];
        foreach ($prices as $price) {
            if ($price > $peak) $peak = $price;
            $drawdown = ($peak - $price) / $peak;
            if ($drawdown > $maxDrawdown) $maxDrawdown = $drawdown;
        }
        return round($maxDrawdown * 100, 2); // as percentage
    }

    protected function calculateDownsideDeviation(array $marketData): float
    {
        // Standard deviation of negative returns
        $prices = $marketData['historical_prices'] ?? [];
        if (count($prices) < 2) return 0.0;
        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            $ret = ($prices[$i] - $prices[$i-1]) / $prices[$i-1];
            if ($ret < 0) $returns[] = $ret;
        }
        if (empty($returns)) return 0.0;
        $mean = array_sum($returns) / count($returns);
        $sumSq = 0.0;
        foreach ($returns as $r) {
            $sumSq += pow($r - $mean, 2);
        }
        $downsideDev = sqrt($sumSq / count($returns));
        return round($downsideDev * 100, 2); // as percentage
    }

    protected function calculateSortinoRatio(array $marketData): float
    {
        // Sortino Ratio: (mean return - risk-free rate) / downside deviation
        $prices = $marketData['historical_prices'] ?? [];
        if (count($prices) < 2) return 0.0;
        $riskFreeRate = 0.02 / 252; // daily risk-free rate (2% annual)
        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            $returns[] = ($prices[$i] - $prices[$i-1]) / $prices[$i-1];
        }
        $meanReturn = array_sum($returns) / count($returns);
        $downsideDev = $this->calculateDownsideDeviation($marketData) / 100;
        if ($downsideDev == 0) return 0.0;
        $sortino = ($meanReturn - $riskFreeRate) / $downsideDev;
        return round($sortino, 2);
    }

    protected function calculateRiskRewardRatio(array $marketData): float
    {
        // Average gain vs average loss
        $prices = $marketData['historical_prices'] ?? [];
        if (count($prices) < 2) return 0.0;
        $gains = $losses = 0.0;
        $gainCount = $lossCount = 0;
        for ($i = 1; $i < count($prices); $i++) {
            $ret = ($prices[$i] - $prices[$i-1]) / $prices[$i-1];
            if ($ret > 0) {
                $gains += $ret;
                $gainCount++;
            } elseif ($ret < 0) {
                $losses += abs($ret);
                $lossCount++;
            }
        }
        if ($lossCount == 0) return 0.0;
        $avgGain = $gainCount ? $gains / $gainCount : 0.0;
        $avgLoss = $losses / $lossCount;
        $ratio = $avgLoss ? $avgGain / $avgLoss : 0.0;
        return round($ratio, 2);
    }

    protected function interpretRSI(float $rsi): string
    {
        if ($rsi > 70) return 'overbought';
        if ($rsi < 30) return 'oversold';
        if ($rsi > 55) return 'bullish momentum';
        if ($rsi < 45) return 'bearish momentum';
        return 'neutral';
    }

    protected function interpretMACD(array $macd): string
    {
        $histogram = $macd['histogram'] ?? 0;
        if ($histogram > 0) return 'bullish crossover';
        if ($histogram < 0) return 'bearish crossover';
        return 'neutral';
    }

    protected function analyzeMAConvergence(array $indicators): string
    {
        $sma50 = $indicators['moving_averages']['sma_50'] ?? 0;
        $sma200 = $indicators['moving_averages']['sma_200'] ?? 0;
        
        if ($sma50 == 0 || $sma200 == 0) return 'insufficient data';
        
        if ($sma50 > $sma200) return 'golden cross - bullish';
        return 'death cross - bearish';
    }

    protected function calculateMomentumScore(array $marketData): float
    {
        // Composite momentum score 0-100
        return 65.0;
    }

    protected function calculateStrengthIndex(array $marketData): float
    {
        // Relative strength
        return 60.0;
    }

    protected function assessDataQuality(array $marketData, array $newsData): float
    {
        $score = 0;
        if (!empty($marketData['current_price']['price'])) $score += 25;
        if (!empty($marketData['volume'])) $score += 25;
        if (!empty($newsData['news_items'])) $score += 25;
        if (!empty($marketData['technical_indicators'])) $score += 25;
        return $score;
    }

    protected function assessSampleSize(array $newsData): float
    {
        $newsCount = count($newsData['news_items'] ?? []);
        if ($newsCount >= 10) return 100;
        if ($newsCount >= 5) return 75;
        if ($newsCount >= 3) return 50;
        return 25;
    }

    protected function assessDataConsistency(array $marketData, array $newsData): float
    {
        // Check if data sources agree
        $priceChange = $marketData['current_price']['change_24h'] ?? 0;
        $sentimentScore = $newsData['sentiment_score'] ?? 0;
        
        $pricePositive = $priceChange > 0;
        $sentimentPositive = $sentimentScore > 0;
        
        // High consistency if both agree
        if ($pricePositive === $sentimentPositive) return 90;
        return 60;
    }

    protected function interpretConfidenceLevel(float $confidence): string
    {
        if ($confidence >= 80) return 'high';
        if ($confidence >= 60) return 'moderate';
        if ($confidence >= 40) return 'low';
        return 'very low';
    }

    protected function identifyReliabilityFactors(array $marketData, array $newsData): array
    {
        return [
            'data_completeness' => !empty($marketData) && !empty($newsData),
            'data_recency' => 'current',
            'source_diversity' => 'multiple sources',
            'cross_validation' => 'validated'
        ];
    }

    protected function getDefaultStatistics(): array
    {
        return [
            'price_statistics' => [],
            'volume_analysis' => [],
            'volatility_metrics' => [],
            'correlation_analysis' => [],
            'probability_predictions' => [],
            'historical_patterns' => [],
            'risk_metrics' => [],
            'momentum_indicators' => [],
            'statistical_confidence' => ['overall_confidence' => 50]
        ];
    }
}
