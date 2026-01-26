<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnhancedNewsDataService
{
    protected $newsApiKey;
    protected $cryptoPanicKey;

    public function __construct()
    {
        $this->newsApiKey = config('services.newsapi.api_key');
        $this->cryptoPanicKey = config('services.cryptopanic.api_key');
    }

    /**
     * Gather comprehensive news and sentiment analysis
     */
    public function gatherNewsData(string $symbol, string $market): array
    {
        $cacheKey = "enhanced_news_{$market}_{$symbol}_" . date('Y-m-d-H');
        
        return Cache::remember($cacheKey, 1800, function () use ($symbol, $market) {
            try {
                $allNews = [];
                
                // Gather from multiple sources
                $sources = [
                    'newsapi' => $this->getNewsApiData($symbol, $market),
                    'cryptopanic' => $market === 'crypto' ? $this->getCryptoPanicData($symbol) : [],
                    'reddit' => $this->getRedditData($symbol, $market)
                ];
                
                // Merge all news sources
                foreach ($sources as $source => $news) {
                    if (!empty($news)) {
                        foreach ($news as &$item) {
                            $item['data_source'] = $source;
                        }
                        $allNews = array_merge($allNews, $news);
                    }
                }
                
                // Deduplicate similar news
                $allNews = $this->deduplicateNews($allNews);
                
                // Perform advanced sentiment analysis
                $analyzedNews = $this->performAdvancedSentimentAnalysis($allNews);
                
                // Sort by relevance and recency
                $analyzedNews = $this->sortByRelevance($analyzedNews, $symbol);
                
                // Extract trending topics
                $trendingTopics = $this->extractAdvancedTrendingTopics($analyzedNews);
                
                // Calculate sentiment metrics
                $sentimentMetrics = $this->calculateSentimentMetrics($analyzedNews);
                
                // Assess news impact on price
                $impactAssessment = $this->assessNewsImpact($analyzedNews, $sentimentMetrics);
                
                return [
                    'news_items' => array_slice($analyzedNews, 0, 15),
                    'total_articles' => count($analyzedNews),
                    'sources_used' => array_keys(array_filter($sources, fn($s) => !empty($s))),
                    'overall_sentiment' => $sentimentMetrics['overall_sentiment'],
                    'sentiment_score' => $sentimentMetrics['sentiment_score'],
                    'sentiment_strength' => $sentimentMetrics['sentiment_strength'],
                    'positive_count' => $sentimentMetrics['positive_count'],
                    'negative_count' => $sentimentMetrics['negative_count'],
                    'neutral_count' => $sentimentMetrics['neutral_count'],
                    'trending_topics' => $trendingTopics,
                    'impact_assessment' => $impactAssessment,
                    'sentiment_breakdown' => $sentimentMetrics['breakdown'],
                    'last_updated' => now()->toIso8601String()
                ];
            } catch (\Exception $e) {
                Log::error('Enhanced News Data Failed: ' . $e->getMessage(), [
                    'symbol' => $symbol,
                    'market' => $market,
                    'trace' => $e->getTraceAsString()
                ]);
                return $this->getDefaultNewsData();
            }
        });
    }

    /**
     * Get news from NewsAPI with validation
     */
    protected function getNewsApiData(string $symbol, string $market): array
    {
        if (empty($this->newsApiKey) || $this->newsApiKey === 'your_newsapi_api_key_here') {
            Log::info('NewsAPI key not configured, skipping');
            return [];
        }

        try {
            $query = $this->buildAdvancedSearchQuery($symbol, $market);
            $from = now()->subDays(7)->toIso8601String();
            
            $response = Http::timeout(15)
                ->retry(2, 100)
                ->get('https://newsapi.org/v2/everything', [
                    'q' => $query,
                    'language' => 'en',
                    'sortBy' => 'publishedAt',
                    'pageSize' => 30,
                    'from' => $from,
                    'apiKey' => $this->newsApiKey
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['status']) && $data['status'] === 'ok') {
                    return $this->formatNewsApiItems($data['articles'] ?? []);
                }
            } else {
                Log::warning('NewsAPI request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NewsAPI error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Get crypto news from CryptoPanic with validation
     */
    protected function getCryptoPanicData(string $symbol): array
    {
        if (empty($this->cryptoPanicKey) || $this->cryptoPanicKey === 'your_cryptopanic_api_key_here') {
            Log::info('CryptoPanic key not configured, skipping');
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 100)
                ->get('https://cryptopanic.com/api/v1/posts/', [
                    'auth_token' => $this->cryptoPanicKey,
                    'currencies' => strtoupper($symbol),
                    'kind' => 'news',
                    'filter' => 'hot'
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatCryptoPanicItems($data['results'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error('CryptoPanic error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Get community sentiment from Reddit
     */
    protected function getRedditData(string $symbol, string $market): array
    {
        try {
            $subreddit = $market === 'crypto' ? 'cryptocurrency' : 'wallstreetbets';
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'ChartSense/1.0'])
                ->get("https://www.reddit.com/r/{$subreddit}/search.json", [
                    'q' => strtoupper($symbol),
                    'sort' => 'hot',
                    'limit' => 15,
                    'restrict_sr' => 1,
                    't' => 'week'
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatRedditItems($data['data']['children'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error('Reddit API error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Build advanced search query with market-specific keywords
     */
    protected function buildAdvancedSearchQuery(string $symbol, string $market): string
    {
        $baseQuery = $symbol;
        
        $marketKeywords = [
            'crypto' => ['cryptocurrency', 'blockchain', 'bitcoin', 'crypto', 'token', 'DeFi'],
            'stock' => ['stock', 'shares', 'earnings', 'nasdaq', 'NYSE', 'market', 'trading'],
            'forex' => ['forex', 'currency', 'exchange rate', 'FX', 'trading', 'central bank']
        ];
        
        $keywords = $marketKeywords[$market] ?? [];
        $keywordString = implode(' OR ', array_slice($keywords, 0, 3));
        
        return "({$baseQuery}) AND ({$keywordString})";
    }

    /**
     * Format NewsAPI items
     */
    protected function formatNewsApiItems(array $articles): array
    {
        return array_map(function ($article) {
            return [
                'title' => $article['title'] ?? 'No title',
                'description' => $article['description'] ?? '',
                'content' => $article['content'] ?? '',
                'url' => $article['url'] ?? '',
                'source' => $article['source']['name'] ?? 'Unknown',
                'author' => $article['author'] ?? 'Unknown',
                'published_at' => $article['publishedAt'] ?? now()->toIso8601String(),
                'image' => $article['urlToImage'] ?? null,
                'sentiment' => 'neutral',
                'relevance_score' => 0
            ];
        }, array_filter($articles, fn($a) => !empty($a['title'])));
    }

    /**
     * Format CryptoPanic items
     */
    protected function formatCryptoPanicItems(array $posts): array
    {
        return array_map(function ($post) {
            $votes = $post['votes'] ?? [];
            return [
                'title' => $post['title'] ?? 'No title',
                'description' => $post['title'] ?? '',
                'content' => '',
                'url' => $post['url'] ?? '',
                'source' => $post['source']['title'] ?? 'CryptoPanic',
                'author' => $post['source']['title'] ?? 'Unknown',
                'published_at' => $post['published_at'] ?? now()->toIso8601String(),
                'image' => null,
                'sentiment' => $this->mapCryptoPanicVotes($votes),
                'votes' => $votes,
                'relevance_score' => ($votes['positive'] ?? 0) + ($votes['important'] ?? 0)
            ];
        }, $posts);
    }

    /**
     * Format Reddit items
     */
    protected function formatRedditItems(array $posts): array
    {
        return array_map(function ($post) {
            $data = $post['data'] ?? [];
            $upvotes = $data['ups'] ?? 0;
            $comments = $data['num_comments'] ?? 0;
            
            return [
                'title' => $data['title'] ?? 'No title',
                'description' => substr($data['selftext'] ?? '', 0, 300),
                'content' => $data['selftext'] ?? '',
                'url' => 'https://reddit.com' . ($data['permalink'] ?? ''),
                'source' => 'Reddit r/' . ($data['subreddit'] ?? 'cryptocurrency'),
                'author' => 'u/' . ($data['author'] ?? 'unknown'),
                'published_at' => isset($data['created_utc']) 
                    ? date('c', $data['created_utc']) 
                    : now()->toIso8601String(),
                'image' => $data['thumbnail'] ?? null,
                'sentiment' => 'neutral',
                'upvotes' => $upvotes,
                'comments' => $comments,
                'upvote_ratio' => $data['upvote_ratio'] ?? 0.5,
                'relevance_score' => $upvotes + ($comments * 2)
            ];
        }, $posts);
    }

    /**
     * Map CryptoPanic votes to sentiment
     */
    protected function mapCryptoPanicVotes(array $votes): string
    {
        $positive = $votes['positive'] ?? 0;
        $negative = $votes['negative'] ?? 0;
        $important = $votes['important'] ?? 0;
        
        $total = $positive + $negative + $important;
        if ($total === 0) return 'neutral';
        
        if ($positive > ($negative * 2)) return 'positive';
        if ($negative > ($positive * 2)) return 'negative';
        return 'neutral';
    }

    /**
     * Deduplicate similar news articles
     */
    protected function deduplicateNews(array $news): array
    {
        $unique = [];
        $seenTitles = [];
        
        foreach ($news as $item) {
            $titleKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $item['title']));
            $titleKey = substr($titleKey, 0, 50); // First 50 chars
            
            if (!isset($seenTitles[$titleKey])) {
                $seenTitles[$titleKey] = true;
                $unique[] = $item;
            }
        }
        
        return $unique;
    }

    /**
     * Perform advanced sentiment analysis using NLP techniques
     */
    protected function performAdvancedSentimentAnalysis(array $newsItems): array
    {
        return array_map(function ($item) {
            if ($item['sentiment'] !== 'neutral') {
                // Already has sentiment from source
                $item['sentiment_confidence'] = 0.7;
                return $item;
            }
            
            $text = strtolower($item['title'] . ' ' . $item['description'] . ' ' . ($item['content'] ?? ''));
            
            // Advanced sentiment analysis
            $sentimentResult = $this->advancedSentimentAnalysis($text);
            $item['sentiment'] = $sentimentResult['sentiment'];
            $item['sentiment_confidence'] = $sentimentResult['confidence'];
            $item['sentiment_score'] = $sentimentResult['score'];
            
            return $item;
        }, $newsItems);
    }

    /**
     * Advanced NLP-based sentiment analysis
     */
    protected function advancedSentimentAnalysis(string $text): array
    {
        // Enhanced keyword lists with weights
        $positiveKeywords = [
            // Strong positive (weight 2.0)
            'surge' => 2.0, 'soar' => 2.0, 'breakout' => 2.0, 'rally' => 2.0,
            'skyrocket' => 2.0, 'boom' => 2.0, 'explosive' => 2.0,
            // Medium positive (weight 1.5)
            'bullish' => 1.5, 'gain' => 1.5, 'profit' => 1.5, 'growth' => 1.5,
            'rise' => 1.5, 'up' => 1.5, 'increase' => 1.5, 'jump' => 1.5,
            // Mild positive (weight 1.0)
            'positive' => 1.0, 'good' => 1.0, 'strong' => 1.0, 'success' => 1.0,
            'win' => 1.0, 'boost' => 1.0, 'momentum' => 1.0, 'recovery' => 1.0,
            'optimistic' => 1.0, 'upgrade' => 1.0, 'outperform' => 1.0
        ];

        $negativeKeywords = [
            // Strong negative (weight -2.0)
            'crash' => -2.0, 'plunge' => -2.0, 'collapse' => -2.0, 'dump' => -2.0,
            'disaster' => -2.0, 'panic' => -2.0, 'catastrophic' => -2.0,
            // Medium negative (weight -1.5)
            'bearish' => -1.5, 'fall' => -1.5, 'loss' => -1.5, 'decline' => -1.5,
            'drop' => -1.5, 'down' => -1.5, 'sell-off' => -1.5, 'slump' => -1.5,
            // Mild negative (weight -1.0)
            'negative' => -1.0, 'risk' => -1.0, 'concern' => -1.0, 'fear' => -1.0,
            'weak' => -1.0, 'warning' => -1.0, 'threat' => -1.0, 'uncertainty' => -1.0,
            'downgrade' => -1.0, 'underperform' => -1.0
        ];

        // Negation words that flip sentiment
        $negationWords = ['not', 'no', 'never', 'nothing', 'neither', 'nobody', 'nowhere', 'barely', 'hardly', 'scarcely'];
        
        $words = preg_split('/\s+/', $text);
        $score = 0;
        $matchCount = 0;
        $negationFlag = false;
        
        foreach ($words as $index => $word) {
            $word = trim($word, '.,!?;:');
            
            // Check for negation
            if (in_array($word, $negationWords)) {
                $negationFlag = true;
                continue;
            }
            
            // Check positive keywords
            if (isset($positiveKeywords[$word])) {
                $weight = $positiveKeywords[$word];
                $score += $negationFlag ? -$weight : $weight;
                $matchCount++;
                $negationFlag = false;
            }
            // Check negative keywords
            elseif (isset($negativeKeywords[$word])) {
                $weight = $negativeKeywords[$word];
                $score += $negationFlag ? -$weight : $weight;
                $matchCount++;
                $negationFlag = false;
            }
        }
        
        // Normalize score
        $normalizedScore = $matchCount > 0 ? $score / $matchCount : 0;
        
        // Calculate confidence based on number of matches and text length
        $wordCount = count($words);
        $confidence = min(0.95, max(0.3, ($matchCount / max($wordCount, 1)) * 2 + 0.3));
        
        // Determine sentiment
        if ($normalizedScore > 0.3) {
            $sentiment = 'positive';
        } elseif ($normalizedScore < -0.3) {
            $sentiment = 'negative';
        } else {
            $sentiment = 'neutral';
        }
        
        return [
            'sentiment' => $sentiment,
            'score' => round($normalizedScore, 3),
            'confidence' => round($confidence, 2),
            'matches' => $matchCount
        ];
    }

    /**
     * Sort news by relevance and recency
     */
    protected function sortByRelevance(array $news, string $symbol): array
    {
        usort($news, function ($a, $b) use ($symbol) {
            // Calculate relevance score
            $aRelevance = $a['relevance_score'] ?? 0;
            $bRelevance = $b['relevance_score'] ?? 0;
            
            // Boost if symbol appears in title
            if (stripos($a['title'], $symbol) !== false) $aRelevance += 10;
            if (stripos($b['title'], $symbol) !== false) $bRelevance += 10;
            
            // Compare relevance first
            if ($aRelevance !== $bRelevance) {
                return $bRelevance <=> $aRelevance;
            }
            
            // Then by recency
            return strtotime($b['published_at']) <=> strtotime($a['published_at']);
        });
        
        return $news;
    }

    /**
     * Extract trending topics with advanced NLP
     */
    protected function extractAdvancedTrendingTopics(array $newsItems): array
    {
        $allWords = [];
        $phrases = [];
        
        // Common stop words to exclude
        $stopWords = ['the', 'is', 'at', 'which', 'on', 'a', 'an', 'as', 'are', 'was', 'were', 
                      'been', 'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
                      'should', 'could', 'may', 'might', 'must', 'can', 'this', 'that', 'these',
                      'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'what', 'who', 'when',
                      'where', 'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
                      'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so',
                      'than', 'too', 'very', 'just', 'but', 'for', 'with', 'about', 'against',
                      'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below',
                      'to', 'from', 'up', 'down', 'in', 'out', 'off', 'over', 'under', 'again'];
        
        foreach ($newsItems as $item) {
            $text = strtolower($item['title'] . ' ' . $item['description']);
            $words = preg_split('/\s+/', $text);
            
            // Extract single words
            foreach ($words as $word) {
                $word = trim($word, '.,!?;:"\'()[]{}');
                if (strlen($word) > 3 && !in_array($word, $stopWords) && !is_numeric($word)) {
                    $allWords[] = $word;
                }
            }
            
            // Extract 2-word phrases
            for ($i = 0; $i < count($words) - 1; $i++) {
                $word1 = trim($words[$i], '.,!?;:"\'()[]{}');
                $word2 = trim($words[$i + 1], '.,!?;:"\'()[]{}');
                
                if (strlen($word1) > 3 && strlen($word2) > 3 && 
                    !in_array($word1, $stopWords) && !in_array($word2, $stopWords)) {
                    $phrases[] = $word1 . ' ' . $word2;
                }
            }
        }
        
        // Count frequencies
        $wordCounts = array_count_values($allWords);
        $phraseCounts = array_count_values($phrases);
        
        arsort($wordCounts);
        arsort($phraseCounts);
        
        // Combine top words and phrases
        $topWords = array_slice(array_keys($wordCounts), 0, 5);
        $topPhrases = array_slice(array_keys($phraseCounts), 0, 3);
        
        return array_merge($topPhrases, $topWords);
    }

    /**
     * Calculate comprehensive sentiment metrics
     */
    protected function calculateSentimentMetrics(array $newsItems): array
    {
        if (empty($newsItems)) {
            return [
                'overall_sentiment' => 'neutral',
                'sentiment_score' => 0,
                'sentiment_strength' => 'weak',
                'positive_count' => 0,
                'negative_count' => 0,
                'neutral_count' => 0,
                'breakdown' => []
            ];
        }
        
        $positive = 0;
        $negative = 0;
        $neutral = 0;
        $totalScore = 0;
        $totalConfidence = 0;
        
        foreach ($newsItems as $item) {
            $sentiment = $item['sentiment'] ?? 'neutral';
            $score = $item['sentiment_score'] ?? 0;
            $confidence = $item['sentiment_confidence'] ?? 0.5;
            
            switch ($sentiment) {
                case 'positive':
                    $positive++;
                    break;
                case 'negative':
                    $negative++;
                    break;
                default:
                    $neutral++;
            }
            
            $totalScore += $score * $confidence;
            $totalConfidence += $confidence;
        }
        
        $total = count($newsItems);
        $avgScore = $totalConfidence > 0 ? $totalScore / $totalConfidence : 0;
        $avgConfidence = $totalConfidence / $total;
        
        // Determine overall sentiment
        $positiveRatio = $positive / $total;
        $negativeRatio = $negative / $total;
        
        if ($positiveRatio > 0.6) {
            $overall = 'very positive';
        } elseif ($positiveRatio > 0.4) {
            $overall = 'positive';
        } elseif ($negativeRatio > 0.6) {
            $overall = 'very negative';
        } elseif ($negativeRatio > 0.4) {
            $overall = 'negative';
        } else {
            $overall = 'neutral';
        }
        
        // Determine sentiment strength
        $dominance = max($positiveRatio, $negativeRatio, $neutral / $total);
        if ($dominance > 0.7) {
            $strength = 'strong';
        } elseif ($dominance > 0.5) {
            $strength = 'moderate';
        } else {
            $strength = 'weak';
        }
        
        return [
            'overall_sentiment' => $overall,
            'sentiment_score' => round($avgScore, 3),
            'sentiment_strength' => $strength,
            'positive_count' => $positive,
            'negative_count' => $negative,
            'neutral_count' => $neutral,
            'confidence' => round($avgConfidence, 2),
            'breakdown' => [
                'positive_ratio' => round($positiveRatio, 2),
                'negative_ratio' => round($negativeRatio, 2),
                'neutral_ratio' => round($neutral / $total, 2)
            ]
        ];
    }

    /**
     * Assess news impact on price movements
     */
    protected function assessNewsImpact(array $newsItems, array $sentimentMetrics): array
    {
        $sentimentScore = $sentimentMetrics['sentiment_score'];
        $sentimentStrength = $sentimentMetrics['sentiment_strength'];
        $recentNewsCount = count(array_filter($newsItems, function($item) {
            $publishedTime = strtotime($item['published_at']);
            return $publishedTime > (time() - 86400); // Last 24 hours
        }));
        
        // Determine impact level
        $impactScore = abs($sentimentScore) * (
            $sentimentStrength === 'strong' ? 1.5 :
            ($sentimentStrength === 'moderate' ? 1.0 : 0.5)
        );
        
        if ($impactScore > 1.5) {
            $impactLevel = 'high';
        } elseif ($impactScore > 0.8) {
            $impactLevel = 'moderate';
        } else {
            $impactLevel = 'low';
        }
        
        // Determine expected direction
        if ($sentimentScore > 0.3) {
            $expectedDirection = 'bullish';
            $confidence = min(90, max(50, 50 + ($impactScore * 30)));
        } elseif ($sentimentScore < -0.3) {
            $expectedDirection = 'bearish';
            $confidence = min(90, max(50, 50 + ($impactScore * 30)));
        } else {
            $expectedDirection = 'neutral';
            $confidence = 50;
        }
        
        // News velocity (how fast news is coming in)
        $newsVelocity = $recentNewsCount > 5 ? 'high' : ($recentNewsCount > 2 ? 'moderate' : 'low');
        
        return [
            'impact_level' => $impactLevel,
            'expected_direction' => $expectedDirection,
            'confidence' => round($confidence),
            'impact_score' => round($impactScore, 2),
            'news_velocity' => $newsVelocity,
            'recent_news_count' => $recentNewsCount,
            'recommendation' => $this->generateNewsRecommendation(
                $expectedDirection, 
                $impactLevel, 
                $newsVelocity
            )
        ];
    }

    /**
     * Generate recommendation based on news analysis
     */
    protected function generateNewsRecommendation(string $direction, string $impact, string $velocity): string
    {
        if ($impact === 'high' && $velocity === 'high') {
            if ($direction === 'bullish') {
                return 'Strong positive news momentum. Consider buying opportunities.';
            } elseif ($direction === 'bearish') {
                return 'Strong negative news momentum. Exercise caution or consider selling.';
            }
        } elseif ($impact === 'moderate') {
            if ($direction === 'bullish') {
                return 'Positive news sentiment detected. Monitor for entry points.';
            } elseif ($direction === 'bearish') {
                return 'Negative news sentiment detected. Watch for volatility.';
            }
        }
        
        return 'Mixed or neutral news sentiment. Wait for clearer signals.';
    }

    /**
     * Get default news data when services fail
     */
    protected function getDefaultNewsData(): array
    {
        return [
            'news_items' => [],
            'total_articles' => 0,
            'sources_used' => [],
            'overall_sentiment' => 'neutral',
            'sentiment_score' => 0,
            'sentiment_strength' => 'weak',
            'positive_count' => 0,
            'negative_count' => 0,
            'neutral_count' => 0,
            'trending_topics' => [],
            'impact_assessment' => [
                'impact_level' => 'low',
                'expected_direction' => 'neutral',
                'confidence' => 50,
                'news_velocity' => 'low',
                'recommendation' => 'No news data available'
            ],
            'sentiment_breakdown' => [],
            'last_updated' => now()->toIso8601String()
        ];
    }
}
