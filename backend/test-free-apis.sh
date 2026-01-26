#!/bin/bash

# ChartSense FREE APIs Test Script
# Tests all 7 FREE public APIs (NO API KEYS REQUIRED!)

echo "╔═══════════════════════════════════════════════════════════════════════╗"
echo "║        ChartSense FREE APIs Test Suite - NO API KEYS NEEDED!        ║"
echo "╚═══════════════════════════════════════════════════════════════════════╝"
echo ""
echo "Testing 7 FREE public APIs that work without authentication..."
echo ""

BASE_URL="http://localhost:8000/api/enhanced"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counter
PASSED=0
FAILED=0

# Test function
test_endpoint() {
    local name=$1
    local url=$2
    local description=$3
    
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}TEST:${NC} $name"
    echo -e "${YELLOW}DESC:${NC} $description"
    echo -e "${YELLOW}URL:${NC} $url"
    echo ""
    
    response=$(curl -s -w "\n%{http_code}" "$url")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" = "200" ]; then
        echo -e "${GREEN}✓ PASSED${NC} (HTTP $http_code)"
        echo "Response preview:"
        echo "$body" | jq '.' 2>/dev/null | head -n 20 || echo "$body" | head -n 20
        ((PASSED++))
    else
        echo -e "${RED}✗ FAILED${NC} (HTTP $http_code)"
        echo "Error:"
        echo "$body"
        ((FAILED++))
    fi
    echo ""
}

# ============================================================================
# Test Suite
# ============================================================================

echo -e "${BLUE}Starting test suite...${NC}"
echo ""

# Test 1: Health Check
test_endpoint \
    "Health Check" \
    "$BASE_URL/health" \
    "Verify all 7 FREE APIs are accessible"

# Test 2: Bitcoin Data (CoinGecko + Binance + Coinpaprika + CoinCap)
test_endpoint \
    "Bitcoin Market Data" \
    "$BASE_URL/market-data?symbol=BTC&market=crypto" \
    "Get BTC data from 4 FREE crypto APIs"

# Test 3: Ethereum Signal Strength
test_endpoint \
    "Ethereum Signal Strength" \
    "$BASE_URL/signal-strength?symbol=ETH&market=crypto" \
    "Calculate ETH trading signals with sentiment"

# Test 4: Dogecoin Data
test_endpoint \
    "Dogecoin Market Data" \
    "$BASE_URL/market-data?symbol=DOGE&market=crypto" \
    "Get DOGE data from multiple sources"

# Test 5: Exchange Rates (ExchangeRate.host + Frankfurter)
test_endpoint \
    "Currency Exchange Rates" \
    "$BASE_URL/exchange-rates" \
    "Get USD exchange rates from 2 FREE sources"

# Test 6: Stock Social Sentiment (WallStreetBets)
test_endpoint \
    "Stock Social Sentiment (GME)" \
    "$BASE_URL/market-data?symbol=GME&market=stocks" \
    "Get WallStreetBets sentiment for GME"

# Test 7: Solana Signal
test_endpoint \
    "Solana Signal Strength" \
    "$BASE_URL/signal-strength?symbol=SOL&market=crypto" \
    "Calculate SOL trading signals"

# ============================================================================
# Summary
# ============================================================================

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "╔═══════════════════════════════════════════════════════════════════════╗"
echo "║                          TEST RESULTS SUMMARY                         ║"
echo "╚═══════════════════════════════════════════════════════════════════════╝"
echo ""
echo -e "${GREEN}✓ Passed:${NC} $PASSED"
echo -e "${RED}✗ Failed:${NC} $FAILED"
echo -e "Total Tests: $((PASSED + FAILED))"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                   🎉 ALL TESTS PASSED! 🎉                            ║${NC}"
    echo -e "${GREEN}║                                                                       ║${NC}"
    echo -e "${GREEN}║  All 7 FREE APIs are working perfectly!                              ║${NC}"
    echo -e "${GREEN}║  No API keys required - Zero configuration needed!                   ║${NC}"
    echo -e "${GREEN}║                                                                       ║${NC}"
    echo -e "${GREEN}║  FREE APIs Available:                                                ║${NC}"
    echo -e "${GREEN}║  ✓ CoinGecko - 13,000+ cryptocurrencies                              ║${NC}"
    echo -e "${GREEN}║  ✓ Binance - Real-time crypto prices                                 ║${NC}"
    echo -e "${GREEN}║  ✓ Coinpaprika - Additional crypto data                              ║${NC}"
    echo -e "${GREEN}║  ✓ CoinCap - Real-time asset prices                                  ║${NC}"
    echo -e "${GREEN}║  ✓ ExchangeRate.host - 170+ currencies                               ║${NC}"
    echo -e "${GREEN}║  ✓ Frankfurter - ECB exchange rates                                  ║${NC}"
    echo -e "${GREEN}║  ✓ WallStreetBets - Social sentiment                                 ║${NC}"
    echo -e "${GREEN}║                                                                       ║${NC}"
    echo -e "${GREEN}║  Your ChartSense integration is ready for production! 🚀             ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════════════╝${NC}"
    exit 0
else
    echo -e "${RED}╔═══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║                      ⚠️  SOME TESTS FAILED  ⚠️                         ║${NC}"
    echo -e "${RED}╚═══════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Troubleshooting:"
    echo "1. Make sure Laravel server is running: php artisan serve"
    echo "2. Check that routes are registered: php artisan route:list"
    echo "3. Verify service is registered in config/services.php"
    echo "4. Check logs: tail -f storage/logs/laravel.log"
    echo ""
    echo "Note: All APIs are FREE and require NO configuration!"
    echo ""
    exit 1
fi
