# ChartSense - Enhanced API Integration Guide

## 📊 Integrated Public APIs for Improved Trading Signal Accuracy

This document details all the APIs integrated from the [public-apis](https://github.com/public-apis/public-apis) repository to enhance trading signal accuracy.

---

## 🎯 API Categories

### 1. **Economic Data APIs**

#### FRED (Federal Reserve Economic Data)
- **URL**: https://fred.stlouisfed.org/docs/api/fred/
- **Auth**: API Key (Free)
- **Purpose**: Economic indicators that affect market trends
- **Data Points**:
  - GDP (Gross Domestic Product)
  - Unemployment Rate
  - Consumer Price Index (Inflation)
  - Federal Funds Rate
  - 10-Year Treasury Yield
  - VIX Volatility Index

**Get API Key**: https://fred.stlouisfed.org/docs/api/api_key.html

```env
FRED_API_KEY=your_fred_api_key_here
```

---

### 2. **Stock Market Data APIs**

#### Twelve Data
- **URL**: https://twelvedata.com/
- **Auth**: API Key
- **Purpose**: Real-time & Historical stock market data
- **Features**: Quotes, time series, technical indicators

```env
TWELVEDATA_API_KEY=your_twelvedata_key_here
```

#### IEX Cloud
- **URL**: https://iexcloud.io/
- **Auth**: API Key
- **Purpose**: Real-time & Historical stock and market data
- **Features**: Company data, financials, news, market data

```env
IEXCLOUD_API_KEY=your_iexcloud_key_here
```

#### Marketstack
- **URL**: https://marketstack.com/
- **Auth**: API Key
- **Purpose**: Real-Time, Intraday & Historical Market Data
- **Features**: End-of-day data, intraday data, tickers

```env
MARKETSTACK_API_KEY=your_marketstack_key_here
```

---

### 3. **Cryptocurrency APIs**

#### CoinGecko
- **URL**: https://www.coingecko.com/en/api
- **Auth**: API Key (Free tier available - No key required)
- **Purpose**: Comprehensive cryptocurrency data
- **Features**:
  - Prices, market cap, volume
  - Historical data
  - Community sentiment
  - Developer activity

```env
COINGECKO_API_KEY=  # Optional for free tier
```

#### Coinpaprika
- **URL**: https://api.coinpaprika.com
- **Auth**: None required for basic usage
- **Purpose**: Cryptocurrency prices and market data

#### CoinCap
- **URL**: https://docs.coincap.io/
- **Auth**: None required
- **Purpose**: Real-time cryptocurrency pricing

---

### 4. **News & Sentiment APIs**

#### MarketAux
- **URL**: https://www.marketaux.com/
- **Auth**: API Key
- **Purpose**: **Live stock market news with sentiment analysis**
- **Key Feature**: Automatically tagged tickers + sentiment scores
- **Why It's Critical**: Provides direct sentiment scores for each news article

```env
MARKETAUX_API_KEY=your_marketaux_key_here
```

#### NewsData
- **URL**: https://newsdata.io/
- **Auth**: API Key
- **Purpose**: Live-breaking news and headlines
- **Features**: News from reputed sources, real-time updates

```env
NEWSDATA_API_KEY=your_newsdata_key_here
```

#### GNews
- **URL**: https://gnews.io/
- **Auth**: API Key
- **Purpose**: Search for news from various sources
- **Features**: Article search, language support

```env
GNEWS_API_KEY=your_gnews_key_here
```

---

### 5. **Currency Exchange APIs**

#### ExchangeRate-API
- **URL**: https://www.exchangerate-api.com
- **Auth**: API Key (Free tier available)
- **Purpose**: Currency conversion rates
- **Features**: 161 currencies, historical data

```env
EXCHANGERATE_API_KEY=  # Optional for basic usage
```

#### Frankfurter
- **URL**: https://www.frankfurter.app/
- **Auth**: None required
- **Purpose**: Exchange rates, currency conversion, time series
- **Note**: Free ECB data

---

### 6. **Technical Analysis & Machine Learning APIs**

#### Unplugg
- **URL**: https://unplu.gg/test_api.html
- **Auth**: API Key
- **Purpose**: Forecasting API for timeseries data
- **Use Case**: Price prediction and trend forecasting

```env
UNPLUGG_API_KEY=your_unplugg_key_here
```

#### Time Door
- **URL**: https://timedoor.io
- **Auth**: API Key
- **Purpose**: Time series analysis API
- **Use Case**: Pattern detection and anomaly detection

```env
TIMEDOOR_API_KEY=your_timedoor_key_here
```

---

### 7. **Sentiment Analysis APIs**

#### Aylien Text Analysis
- **URL**: https://docs.aylien.com/textapi/
- **Auth**: API Key + App ID
- **Purpose**: Natural language processing and sentiment analysis
- **Features**: Entity extraction, sentiment, summarization

```env
AYLIEN_API_KEY=your_aylien_key_here
AYLIEN_API_ID=your_aylien_app_id_here
```

#### MeaningCloud
- **URL**: https://www.meaningcloud.com/
- **Auth**: API Key
- **Purpose**: Sentiment analysis
- **Features**: Multilingual sentiment analysis

```env
MEANINGCLOUD_API_KEY=your_meaningcloud_key_here
```

---

### 8. **Social Sentiment APIs**

#### WallStreetBets API
- **URL**: https://dashboard.nbshare.io/apps/reddit/api/
- **Auth**: None required
- **Purpose**: Stock sentiment from Reddit's WallStreetBets
- **Features**: Mention counts, sentiment scores

**Note**: No API key required, but rate limited.

---

## 🚀 Quick Start

### 1. **Copy Environment Variables**

Add these to your `/workspaces/ChartSense/backend/.env` file:

```env
# ===========================================
# ENHANCED MARKET DATA APIs
# ===========================================

# Economic Data
FRED_API_KEY=

# Stock Market Data
TWELVEDATA_API_KEY=
IEXCLOUD_API_KEY=
MARKETSTACK_API_KEY=

# Cryptocurrency (Free tier available)
COINGECKO_API_KEY=

# News & Sentiment
MARKETAUX_API_KEY=
NEWSDATA_API_KEY=
GNEWS_API_KEY=

# Currency Exchange (Optional)
EXCHANGERATE_API_KEY=

# Technical Analysis & ML
UNPLUGG_API_KEY=
TIMEDOOR_API_KEY=

# Sentiment Analysis
AYLIEN_API_KEY=
AYLIEN_API_ID=
MEANINGCLOUD_API_KEY=
```

### 2. **Priority APIs to Get First**

For **maximum trading signal accuracy**, prioritize getting API keys for:

1. **FRED** (Economic indicators) - FREE
2. **MarketAux** (News sentiment) - Critical for sentiment analysis
3. **IEX Cloud** or **Twelve Data** (Stock data)
4. **CoinGecko** (Crypto data) - FREE, no key needed
5. **Aylien** (Text sentiment analysis)

### 3. **Free Tier APIs (No Keys Required)**

These work immediately without API keys:
- CoinGecko (Crypto data)
- Coinpaprika (Crypto data)
- CoinCap (Crypto data)
- Frankfurter (Currency exchange)
- WallStreetBets (Social sentiment)
- ExchangeRate.host (Currency exchange)

---

## 📖 Usage Examples

### Get Comprehensive Market Data

```php
use App\Services\EnhancedMarketDataService;

$service = new EnhancedMarketDataService();

// For stocks
$data = $service->getComprehensiveMarketData('AAPL', 'stocks');

// For cryptocurrency
$data = $service->getComprehensiveMarketData('BTC', 'crypto');
```

### Response Structure

```json
{
  "economic_indicators": {
    "GDP": {"value": "25000", "date": "2026-01-01"},
    "unemployment_rate": {"value": "3.5", "date": "2026-01-01"},
    "inflation_cpi": {"value": "3.2", "date": "2026-01-01"},
    "federal_funds_rate": {"value": "5.25", "date": "2026-01-01"},
    "treasury_10y": {"value": "4.5", "date": "2026-01-01"},
    "vix": {"value": "15.2", "date": "2026-01-26"}
  },
  "stock_data": {
    "iex": {
      "price": 185.50,
      "change": 2.30,
      "change_percent": 1.25,
      "volume": 75000000,
      "market_cap": 2900000000000,
      "pe_ratio": 28.5
    },
    "twelvedata": {
      "price": 185.48,
      "volume": 75100000,
      "change": 2.28,
      "percent_change": 1.24
    }
  },
  "news_sentiment": {
    "positive": 6,
    "negative": 2,
    "neutral": 2,
    "overall_score": 0.45,
    "article_count": 10,
    "recent_headlines": [...]
  },
  "market_sentiment": {
    "analyst_ratings": {
      "buy": 15,
      "hold": 8,
      "sell": 2,
      "strong_buy": 10,
      "strong_sell": 1
    }
  },
  "social_sentiment": {
    "mentions": 1250,
    "sentiment": "positive",
    "score": 0.65
  },
  "exchange_rates": {
    "base": "USD",
    "date": "2026-01-26",
    "rates": {
      "EUR": 0.92,
      "GBP": 0.79,
      "JPY": 148.50,
      "CNY": 7.24,
      "CHF": 0.86
    }
  },
  "market_breadth": {
    "SPY": {"price": 485.20, "change_percent": "0.5%"},
    "QQQ": {"price": 395.80, "change_percent": "0.8%"},
    "DIA": {"price": 380.50, "change_percent": "0.3%"}
  }
}
```

### Calculate Aggregated Signals

```php
$signals = $service->calculateAggregatedSignalStrength($data);

// Returns:
{
  "technical": 0.6,
  "sentiment": 0.45,
  "economic": 0.5,
  "overall": 0.52,
  "confidence": 85
}
```

---

## 🎯 How These APIs Improve Signal Accuracy

### 1. **Economic Context** (FRED)
- Provides macro-economic backdrop
- Helps identify market cycles
- Interest rate changes affect all assets

### 2. **Multi-Source Price Data**
- Cross-validates pricing from multiple sources
- Reduces single-source errors
- Identifies arbitrage opportunities

### 3. **Sentiment Analysis** (MarketAux, Aylien)
- News sentiment is a leading indicator
- Catches market-moving events early
- Quantifies qualitative information

### 4. **Social Sentiment** (WallStreetBets)
- Retail investor sentiment
- Can predict short-term volatility
- Early warning for meme stock movements

### 5. **Cross-Asset Analysis**
- Currency rates affect international stocks
- Crypto correlation with tech stocks
- Market breadth shows overall health

---

## 📊 Signal Accuracy Improvements

By integrating these APIs, ChartSense now provides:

1. **Multi-Factor Analysis**: Combines technical, fundamental, sentiment, and economic factors
2. **Confidence Scores**: Based on data source availability (more sources = higher confidence)
3. **Early Warning**: News sentiment can predict price movements
4. **Context-Aware**: Economic conditions provide trading context
5. **Cross-Validation**: Multiple data sources validate each other

---

## 🔧 API Rate Limits & Best Practices

### Rate Limit Summary

| API | Free Tier | Paid Tier |
|-----|-----------|-----------|
| FRED | 120 calls/min | Same |
| IEX Cloud | 50k msgs/mo | Varies |
| Twelve Data | 800 calls/day | Varies |
| CoinGecko | 10-50 calls/min | Higher |
| MarketAux | 100 calls/mo | Varies |
| NewsData | 200 calls/day | Varies |

### Best Practices

1. **Use Caching**: All API calls are cached for 30 minutes
2. **Fallback Strategy**: Service degrades gracefully if APIs fail
3. **Rate Limiting**: Built-in delays to respect rate limits
4. **Error Handling**: Logs errors, continues with available data
5. **Prioritize**: Use free APIs where possible

---

## 🛠️ Service Architecture

```
EnhancedMarketDataService
├── Economic Data (FRED)
├── Stock Data (IEX, Twelve Data, Alpha Vantage)
├── Crypto Data (CoinGecko, Binance)
├── News Sentiment (MarketAux, NewsData)
├── Social Sentiment (WallStreetBets)
├── Currency Exchange (ExchangeRate, Frankfurter)
└── Signal Aggregation (Multi-factor analysis)
```

---

## 📚 Additional Resources

- [Public APIs Repository](https://github.com/public-apis/public-apis)
- [FRED API Documentation](https://fred.stlouisfed.org/docs/api/fred/)
- [IEX Cloud Docs](https://iexcloud.io/docs/)
- [CoinGecko API Docs](https://www.coingecko.com/en/api/documentation)
- [MarketAux Docs](https://www.marketaux.com/documentation)

---

## ✅ Setup Checklist

- [ ] Add API keys to `.env` file
- [ ] Test FRED API (economic data)
- [ ] Test IEX Cloud or Twelve Data (stock data)
- [ ] Test CoinGecko (crypto data - no key needed)
- [ ] Test MarketAux (news sentiment)
- [ ] Test WallStreetBets API (social sentiment - no key needed)
- [ ] Run comprehensive data fetch test
- [ ] Verify signal aggregation works
- [ ] Check cache is working properly
- [ ] Monitor API usage and rate limits

---

**Last Updated**: January 26, 2026  
**Version**: 1.0.0  
**Service**: EnhancedMarketDataService
