# ChartSense Backend API

Laravel-based backend for ChartSense - AI-powered trading chart analysis with comprehensive market data, news sentiment, and statistical analysis.

## 🚀 Features

### AI Agent Integration
- **Multi-Agent Analysis**: Uses Google Gemini Pro Vision and OpenAI GPT-4 Vision
- **Consensus Building**: Combines insights from multiple AI agents for more accurate predictions
- **Context-Aware**: Analyzes charts with real-time market data, news, and statistical context

### Data Sources
- **Market Data**: Real-time prices, volume, technical indicators from multiple APIs
- **News Sentiment**: Aggregates news from multiple sources with AI-powered sentiment analysis
- **Statistical Analysis**: Advanced metrics including probability predictions, risk analysis, and momentum indicators

### Services Architecture
1. **AIAgentService**: Orchestrates multiple AI agents for chart analysis
2. **MarketDataService**: Gathers real-time market data from trusted sources
3. **NewsDataService**: Collects and analyzes news sentiment
4. **StatisticalAnalysisService**: Performs comprehensive statistical calculations

## 📋 Prerequisites

- PHP 8.2 or higher
- Composer
- Laravel 12.x

## 🛠 Installation

1. **Install Dependencies**
```bash
cd backend
composer install
```

2. **Configure Environment**
```bash
cp .env.example .env
php artisan key:generate
```

3. **Configure API Keys**

Edit `.env` and add your API keys:

```env
# AI Services (Required for chart analysis)
GEMINI_API_KEY=your_google_gemini_api_key
OPENAI_API_KEY=your_openai_api_key_optional

# Market Data (Choose at least one)
ALPHAVANTAGE_API_KEY=your_alphavantage_key
POLYGON_API_KEY=your_polygon_key
FINNHUB_API_KEY=your_finnhub_key

# News Services (Optional but recommended)
NEWSAPI_API_KEY=your_newsapi_key
CRYPTOPANIC_API_KEY=your_cryptopanic_key

# Frontend URL for CORS
FRONTEND_URL=http://localhost:5173
```

4. **Run Migrations**
```bash
php artisan migrate
```

5. **Start Development Server**
```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## 🔑 Getting API Keys

### Required Services

1. **Google Gemini API** (Primary AI Agent)
   - Visit: https://makersuite.google.com/app/apikey
   - Free tier available
   - Required for chart analysis

2. **OpenAI API** (Secondary AI Agent - Optional)
   - Visit: https://platform.openai.com/api-keys
   - Enhances analysis accuracy
   - Optional but recommended

### Market Data Services (Choose at least one)

3. **Finnhub** (Stock & Crypto Data)
   - Visit: https://finnhub.io/register
   - Free tier: 60 API calls/minute
   - Recommended for stock market data

4. **Alpha Vantage** (Forex & Stock Data)
   - Visit: https://www.alphavantage.co/support/#api-key
   - Free tier: 25 calls/day
   - Good for forex data

5. **Polygon.io** (Premium Market Data)
   - Visit: https://polygon.io/
   - Free tier available
   - Comprehensive market data

### News Services (Optional but enhances analysis)

6. **NewsAPI** (General Financial News)
   - Visit: https://newsapi.org/register
   - Free tier: 100 requests/day
   - Broad news coverage

7. **CryptoPanic** (Crypto News)
   - Visit: https://cryptopanic.com/developers/api/
   - Free tier available
   - Specialized in crypto news

## 📡 API Endpoints

### Health Check
```http
GET /api/health
```

### Comprehensive Chart Analysis
```http
POST /api/chart/analyze
Content-Type: application/json

{
  "image": "base64_encoded_image_data",
  "symbol": "BTC",
  "market": "crypto",
  "additional_context": "Optional context"
}
```

**Response:**
```json
{
  "success": true,
  "analysis": {
    "signal": "Buy",
    "confidence": 85,
    "trend": "Bullish",
    "patterns": ["Ascending Triangle"],
    "supports": ["48000", "47500"],
    "resistances": ["49500", "50000"],
    "summary": "Comprehensive analysis..."
  },
  "market_data": {
    "current_price": { "price": 48250, "change_24h": 2.3 },
    "volume": { "volume_24h": 32000000000 },
    "sentiment": { "fear_greed_index": 65 }
  },
  "news_sentiment": {
    "overall_sentiment": "positive",
    "sentiment_score": 0.45,
    "recent_news": [...]
  },
  "statistical_analysis": {
    "probability_predictions": { "price_up_probability": 75 },
    "risk_metrics": { "risk_level": "moderate" }
  }
}
```

### Market Data Only
```http
POST /api/chart/market-data
Content-Type: application/json

{
  "symbol": "BTC",
  "market": "crypto"
}
```

### News Data Only
```http
POST /api/chart/news
Content-Type: application/json

{
  "symbol": "BTC",
  "market": "crypto"
}
```

### Statistical Analysis Only
```http
POST /api/chart/statistics
Content-Type: application/json

{
  "symbol": "BTC",
  "market": "crypto"
}
```

## 🏗 Architecture

```
backend/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   │           └── ChartAnalysisController.php
│   └── Services/
│       ├── AIAgentService.php           # Multi-agent AI analysis
│       ├── MarketDataService.php        # Real-time market data
│       ├── NewsDataService.php          # News aggregation & sentiment
│       └── StatisticalAnalysisService.php # Advanced statistics
├── config/
│   ├── services.php                     # API key configuration
│   └── cors.php                         # CORS settings
└── routes/
    └── api.php                          # API routes
```

## 🔄 Data Flow

1. **Client Request** → Upload chart image with symbol/market info
2. **Market Data Gathering** → Fetch real-time data from APIs
3. **News Collection** → Aggregate news and analyze sentiment
4. **Statistical Analysis** → Calculate probabilities and risk metrics
5. **AI Agent Processing** → Analyze chart with full context
6. **Response Synthesis** → Combine all insights into comprehensive analysis

## 🧪 Testing

Test the API using cURL:

```bash
# Health check
curl http://localhost:8000/api/health

# Test chart analysis (with sample base64 image)
curl -X POST http://localhost:8000/api/chart/analyze \
  -H "Content-Type: application/json" \
  -d '{
    "image": "base64_image_data_here",
    "symbol": "BTC",
    "market": "crypto"
  }'
```

## 🔧 Configuration

### Cache Settings
The backend uses Laravel's cache system to store market and news data:
- Market data: Cached for 30 minutes
- News data: Cached for 60 minutes

### Rate Limiting
Consider implementing rate limiting for production:
```php
// In RouteServiceProvider or routes/api.php
Route::middleware(['throttle:60,1'])->group(function () {
    // Your routes here
});
```

## 📊 Supported Markets

- **crypto**: Cryptocurrency (BTC, ETH, etc.)
- **stock**: Stock market (AAPL, TSLA, etc.)
- **forex**: Foreign exchange (EURUSD, GBPUSD, etc.)

## 🚨 Error Handling

All endpoints return consistent error responses:

```json
{
  "success": false,
  "error": "Error type",
  "message": "Detailed error message"
}
```

## 🔐 Security Considerations

1. **API Keys**: Never commit `.env` file with real API keys
2. **CORS**: Configure allowed origins in production
3. **Rate Limiting**: Implement rate limiting for public APIs
4. **Input Validation**: All inputs are validated before processing
5. **Error Messages**: Sensitive information not exposed in errors

## 📈 Performance Optimization

1. **Caching**: Market and news data cached to reduce API calls
2. **Async Processing**: Consider queue workers for heavy analysis
3. **CDN**: Use CDN for static assets
4. **Database**: Use Redis for cache in production

## 🐛 Debugging

Enable debug mode in `.env`:
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

Check logs:
```bash
tail -f storage/logs/laravel.log
```

## 📝 License

MIT License - See LICENSE file for details

## 🤝 Contributing

Contributions welcome! Please read CONTRIBUTING.md for details.

## 📞 Support

For issues or questions, please open an issue on GitHub.
