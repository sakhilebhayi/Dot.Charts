<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NewsDataService
{
    protected $newsApiKey;
    protected $alphaVantageKey;
    protected $rapidApiKey;

    public function __construct()
    {
        $this->newsApiKey = config('services.newsapi.api_key');
        $this->alphaVantageKey = config('services.alphavantage.api_key');
        $this->rapidApiKey = config('services.rapidapi.api_key');
    }

    /**
     * Gather news and sentiment from multiple sources
     */
    public function gatherNewsData(string $symbol, string $market): array
    {
        $cacheKey = "news_data_{$market}_{$symbol}_" . date('Y-m-d-H');
        
        return Cache::remember($cacheKey, 3600, function () use ($symbol, $market) {
            try {
                $allNews = [];

                // Get financial news
                $financialNews = $this->getFinancialNews($symbol, $market);
                $allNews = array_merge($allNews, $financialNews);

                // Get crypto-specific news if applicable
                if ($market === 'crypto') {
                    $cryptoNews = $this->getCryptoNews($symbol);
                    $allNews = array_merge($allNews, $cryptoNews);
                }

                // Analyze sentiment of all news
                $analyzedNews = $this->analyzeSentiment($allNews);

                // Calculate overall sentiment score
                $overallSentiment = $this->calculateOverallSentiment($analyzedNews);

                return [
                    'news_items' => array_slice($analyzedNews, 0, 10), // Top 10 most relevant
                    'overall_sentiment' => $overallSentiment,
                    'sentiment_score' => $this->getSentimentScore($analyzedNews),
                    'positive_count' => $this->countBySentiment($analyzedNews, 'positive'),
                    'negative_count' => $this->countBySentiment($analyzedNews, 'negative'),
                    'neutral_count' => $this->countBySentiment($analyzedNews, 'neutral'),
                    'trending_topics' => $this->extractTrendingTopics($analyzedNews)
                ];
            } catch (\Exception $e) {
                Log::error('News Data Gathering Failed: ' . $e->getMessage());
                return $this->getDefaultNewsData();
            }
        });
    }

    /**
     * Get financial news from NewsAPI
     */
    protected function getFinancialNews(string $symbol, string $market): array
    {
        if (empty($this->newsApiKey)) {
            return [];
        }

        try {
            $query = $this->buildSearchQuery($symbol, $market);
            $response = Http::timeout(15)->get('https://newsapi.org/v2/everything', [
                'q' => $query,
                'language' => 'en',
                'sortBy' => 'publishedAt',
                'pageSize' => 20,
                'apiKey' => $this->newsApiKey
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatNewsItems($data['articles'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error('NewsAPI error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Get crypto-specific news
     */
    protected function getCryptoNews(string $symbol): array
    {
        try {
            // CryptoPanic API (free tier available)
            $response = Http::timeout(15)->get('https://cryptopanic.com/api/v1/posts/', [
                'auth_token' => config('services.cryptopanic.api_key'),
                'currencies' => strtoupper($symbol),
                'kind' => 'news'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatCryptoNews($data['results'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error('CryptoPanic API error: ' . $e->getMessage());
        }

        // Fallback to CoinGecko news
        try {
            $response = Http::timeout(15)->get("https://www.reddit.com/r/cryptocurrency/search.json", [
                'q' => strtoupper($symbol),
                'sort' => 'hot',
                'limit' => 10,
                'restrict_sr' => 1
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatRedditNews($data['data']['children'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error('Reddit API error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Build search query based on market type
     */
    protected function buildSearchQuery(string $symbol, string $market): string
    {
        $queries = [
            'crypto' => "{$symbol} OR cryptocurrency OR bitcoin OR crypto market",
            'stock' => "{$symbol} OR stock market OR trading OR earnings",
            'forex' => "{$symbol} OR forex OR currency OR exchange rate"
        ];

        return $queries[$market] ?? $symbol;
    }

    /**
     * Format news items into standard structure
     */
    protected function formatNewsItems(array $articles): array
    {
        return array_map(function ($article) {
            return [
                'title' => $article['title'] ?? 'No title',
                'description' => $article['description'] ?? '',
                'url' => $article['url'] ?? '',
                'source' => $article['source']['name'] ?? 'Unknown',
                'published_at' => $article['publishedAt'] ?? now()->toIso8601String(),
                'image' => $article['urlToImage'] ?? null,
                'sentiment' => 'neutral' // Will be analyzed later
            ];
        }, $articles);
    }

    protected function formatCryptoNews(array $posts): array
    {
        return array_map(function ($post) {
            return [
                'title' => $post['title'] ?? 'No title',
                'description' => $post['title'] ?? '',
                'url' => $post['url'] ?? '',
                'source' => $post['source']['title'] ?? 'CryptoPanic',
                'published_at' => $post['published_at'] ?? now()->toIso8601String(),
                'sentiment' => $this->mapCryptoPanicVote($post['votes'] ?? []),
                'votes' => $post['votes'] ?? []
            ];
        }, $posts);
    }

    protected function formatRedditNews(array $posts): array
    {
        return array_map(function ($post) {
            $data = $post['data'] ?? [];
            return [
                'title' => $data['title'] ?? 'No title',
                'description' => substr($data['selftext'] ?? '', 0, 200),
                'url' => 'https://reddit.com' . ($data['permalink'] ?? ''),
                'source' => 'r/' . ($data['subreddit'] ?? 'cryptocurrency'),
                'published_at' => isset($data['created_utc']) 
                    ? date('c', $data['created_utc']) 
                    : now()->toIso8601String(),
                'sentiment' => $this->determineRedditSentiment($data),
                'upvotes' => $data['ups'] ?? 0,
                'comments' => $data['num_comments'] ?? 0
            ];
        }, $posts);
    }

    protected function mapCryptoPanicVote(array $votes): string
    {
        $positive = $votes['positive'] ?? 0;
        $negative = $votes['negative'] ?? 0;
        
        if ($positive > $negative * 1.5) return 'positive';
        if ($negative > $positive * 1.5) return 'negative';
        return 'neutral';
    }

    protected function determineRedditSentiment(array $post): string
    {
        $upvotes = $post['ups'] ?? 0;
        $upvoteRatio = $post['upvote_ratio'] ?? 0.5;
        
        if ($upvoteRatio > 0.8 && $upvotes > 100) return 'positive';
        if ($upvoteRatio < 0.4) return 'negative';
        return 'neutral';
    }

    /**
     * Analyze sentiment of news using basic NLP
     */
    protected function analyzeSentiment(array $newsItems): array
    {
        return array_map(function ($item) {
            if ($item['sentiment'] !== 'neutral') {
                return $item; // Already analyzed
            }

            $text = strtolower($item['title'] . ' ' . $item['description']);
            $sentiment = $this->basicSentimentAnalysis($text);
            $item['sentiment'] = $sentiment;
            $item['sentiment_confidence'] = $this->calculateSentimentConfidence($text);

            return $item;
        }, $newsItems);
    }

    /**
     * Basic sentiment analysis using keyword matching
     */
    protected function basicSentimentAnalysis(string $text): string
    {
        $positiveKeywords = [
            'bullish', 'rally', 'surge', 'gain', 'profit', 'growth', 'rise', 'up',
            'positive', 'breakthrough', 'success', 'win', 'increase', 'boost',
            'soar', 'jump', 'breakout', 'momentum', 'strong', 'recovery'
        ];

        $negativeKeywords = [
            'bearish', 'crash', 'fall', 'loss', 'decline', 'drop', 'down',
            'negative', 'risk', 'concern', 'fear', 'sell-off', 'plunge',
            'decrease', 'weak', 'collapse', 'warning', 'threat', 'dump'
        ];

        $positiveScore = 0;
        $negativeScore = 0;

        foreach ($positiveKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $positiveScore++;
            }
        }

        foreach ($negativeKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $negativeScore++;
            }
        }

        if ($positiveScore > $negativeScore) return 'positive';
        if ($negativeScore > $positiveScore) return 'negative';
        return 'neutral';
    }

    protected function calculateSentimentConfidence(string $text): float
    {
        // Simple confidence based on text length and keyword density
        $wordCount = str_word_count($text);
        if ($wordCount < 5) return 0.3;
        if ($wordCount < 15) return 0.6;
        return 0.8;
    }

    /**
     * Calculate overall sentiment from all news
     */
    protected function calculateOverallSentiment(array $newsItems): string
    {
        if (empty($newsItems)) {
            return 'neutral';
        }

        $positive = $this->countBySentiment($newsItems, 'positive');
        $negative = $this->countBySentiment($newsItems, 'negative');
        $total = count($newsItems);

        $positiveRatio = $positive / $total;
        $negativeRatio = $negative / $total;

        if ($positiveRatio > 0.6) return 'very positive';
        if ($positiveRatio > 0.4) return 'positive';
        if ($negativeRatio > 0.6) return 'very negative';
        if ($negativeRatio > 0.4) return 'negative';
        return 'neutral';
    }

    protected function getSentimentScore(array $newsItems): float
    {
        if (empty($newsItems)) {
            return 0;
        }

        $score = 0;
        foreach ($newsItems as $item) {
            $confidence = $item['sentiment_confidence'] ?? 0.5;
            
            switch ($item['sentiment']) {
                case 'positive':
                    $score += 1 * $confidence;
                    break;
                case 'negative':
                    $score -= 1 * $confidence;
                    break;
            }
        }

        return round($score / count($newsItems), 2);
    }

    protected function countBySentiment(array $newsItems, string $sentiment): int
    {
        return count(array_filter($newsItems, function ($item) use ($sentiment) {
            return ($item['sentiment'] ?? 'neutral') === $sentiment;
        }));
    }

    /**
     * Extract trending topics from news
     */
    protected function extractTrendingTopics(array $newsItems): array
    {
        $allWords = [];
        
        foreach ($newsItems as $item) {
            $text = strtolower($item['title']);
            $words = preg_split('/\s+/', $text);
            
            foreach ($words as $word) {
                $word = trim($word, '.,!?;:');
                if (strlen($word) > 4) { // Only words longer than 4 chars
                    $allWords[] = $word;
                }
            }
        }

        // Count word frequency
        $wordCounts = array_count_values($allWords);
        arsort($wordCounts);

        // Return top 5 topics
        return array_slice(array_keys($wordCounts), 0, 5);
    }

    protected function getDefaultNewsData(): array
    {
        return [
            'news_items' => [],
            'overall_sentiment' => 'neutral',
            'sentiment_score' => 0,
            'positive_count' => 0,
            'negative_count' => 0,
            'neutral_count' => 0,
            'trending_topics' => []
        ];
    }
}
