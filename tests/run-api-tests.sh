#!/usr/bin/env bash
set -euo pipefail

#BASE_URL=https://api-straatnamen.goudatijdmachine.nl
BASE_URL=https://www.goudatijdmachine.nl/api-straatnamen

# API Testing Script for Gouda Tijdmachine Straatnamen API
# Tests all endpoints defined in the OpenAPI specification (except for clear cache)

TOTAL_TESTS=0
PASSED_TESTS=0
TESTHTML="qa-results/index.html"

mkdir -p qa-results

echo '<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Straatnamen API testresultaten &raquo; Gouda Tijdmachine</title>
    <link rel="stylesheet" type="text/css" href="https://www.goudatijdmachine.nl/api-straatnamen/assets/swagger-ui.css" >
    <style>
    html { box-sizing: border-box; }
    *, *:before, *:after { box-sizing: inherit; }
    body { margin:0 6vw; background: #fafafa; font-family: sans-serif; padding:10px}
    h1 { background: url("https://www.goudatijdmachine.nl/api-straatnamen/assets/gtm-logo-2025.svg") no-repeat right center; background-size: 100px auto; line-height: 100px; font-size:36px}
    h2 { color: #3795ad;margin:2em 0 1em 0}
    a, a:visited { color: #3795ad; text-decoration: none}
    h3 { padding-top:1em }
    ul { line-height: 1.5em }
    .fail { border-left:5px solid red }
    .pass { border-left:5px solid green }
    xmp { white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; width: 100%; box-sizing: border-box; max-height:100px; overflow-y:auto }
    </style>
    <link rel="icon" type="image/svg+xml" href="https://www.goudatijdmachine.nl/api-straatnamen/assets/gtm-logo-2025.svg">
</head>
<body>
<h1>Testresultaten Gouda Tijdmachine Straatnamen API</h1>
<summary>
' > $TESTHTML

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Function to print test header
print_test_header() {
    echo -e "\n${CYAN}===== Testing: $1 =====${NC}"
    echo "<h2>$1</h2>" >> $TESTHTML
}

# Function to test API endpoint
test_endpoint() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local description=$4
    local printbody="${5:-}"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    echo -e "\n${YELLOW}Test $TOTAL_TESTS: $description${NC}"
    echo "<h3>Test $TOTAL_TESTS: $description</h3>" >> $TESTHTML

    # First run to warm up
    curl_exit0=0
    start_time0=$(date +%s%3N)
    if [ -z "$printbody" ]; then
        response0=$(curl -s -w "\n%{http_code}" -X $method "$endpoint") || curl_exit0=$?
    else
        # Discard body (e.g. binary responses) to avoid null-byte warnings in $(...).
        curl -s -o /dev/null -X $method "$endpoint" || curl_exit0=$?
    fi
    end_time0=$(date +%s%3N)
    response_time0=$((end_time0 - start_time0))

    # Second run with filled cache
    curl_exit=0
    start_time=$(date +%s%3N)
    if [ -z "$printbody" ]; then
        response=$(curl -s -w "\n%{http_code}" -X $method "$endpoint") || curl_exit=$?
    else
        http_code=$(curl -s -o /dev/null -w "%{http_code}" -X $method "$endpoint") || curl_exit=$?
    fi
    end_time=$(date +%s%3N)
    response_time=$((end_time - start_time))

    if [ "$curl_exit" -ne 0 ]; then
        http_code=""
        body=""
    elif [ -z "$printbody" ]; then
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | head -n -1)
    else
        body=""
    fi

    if [ "$curl_exit" -ne 0 ]; then
        echo -e "${RED}✗ FAIL (curl exit $curl_exit)${NC} (${response_time}ms)"
        echo "<ul class='fail'>" >> $TESTHTML
    elif [ "$http_code" -eq "$expected_status" ]; then
        echo -e "${GREEN}✓ PASS${NC} (${response_time}ms)"
        PASSED_TESTS=$((PASSED_TESTS + 1))
        echo "<ul class='pass'>" >> $TESTHTML
    else
        echo -e "${RED}✗ FAIL (expected $expected_status, got $http_code)${NC} (${response_time}ms)"
        echo "<ul class='fail'>" >> $TESTHTML
    fi

    echo "Request: $method $endpoint"
    echo "<li><strong>Request</strong>: $method <a href=""$endpoint"">$endpoint</a></li>" >> $TESTHTML

    echo "Response code: $http_code"
    echo "<li><strong>Response code</strong>: $http_code (expected $expected_status)</li>" >> $TESTHTML

    echo "Response time: ${response_time0}ms (no cache) / ${response_time}ms (with cache)"
    echo "<li><strong>Response time</strong>: ${response_time0}ms (no cache) / ${response_time}ms (with cache)</li>" >> $TESTHTML

    if [ -z "$printbody" ]; then
        echo "Response body: $body" | head -c 200
        #echo "<li><strong>Response body</strong>: <pre>$body</pre>" >> $TESTHTML
        if [ ${#body} -gt 200 ]; then
            echo "..."
        fi
        #echo "</xmp></li>" >> $TESTHTML
    fi
    echo "</ul>"  >> $TESTHTML

}

# Function to test endpoint with JSON validation.
# Optional args:
#   $5 accept_header — Accept header to send (default: application/json)
#   $6 jq_filter     — jq expression that must evaluate truthy against the body
#                      on a 2xx response (use `jq -e` semantics: false/null = fail)
test_json_endpoint() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local description=$4
    local accept="${5:-application/json}"
    local jq_filter="${6:-}"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    # First run to warm up
    curl_exit0=0
    start_time0=$(date +%s%3N)
    response0=$(curl -s -H "Accept: $accept" -w "\n%{http_code}" -X $method "$endpoint") || curl_exit0=$?
    end_time0=$(date +%s%3N)
    response_time0=$((end_time0 - start_time0))

    # Second run with filled cache
    curl_exit=0
    start_time=$(date +%s%3N)
    response=$(curl -s -H "Accept: $accept" -w "\n%{http_code}" -X $method "$endpoint") || curl_exit=$?
    end_time=$(date +%s%3N)
    response_time=$((end_time - start_time))

    if [ "$curl_exit" -ne 0 ]; then
        http_code=""
        body=""
    else
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | head -n -1)
    fi

    echo -e "\n${YELLOW}Test $TOTAL_TESTS: $description${NC}"
    echo "<h3>Test $TOTAL_TESTS: $description</h3>" >> $TESTHTML

    # Check if response is valid JSON (only if status is 200)
    json_valid=true
    if [ "$curl_exit" -eq 0 ] && [ "$http_code" -eq 200 ]; then
        echo "$body" | jq . > /dev/null 2>&1 || json_valid=false
    fi

    # Optional body-shape assertion via jq -e (op zowel succes- als foutresponses,
    # zolang het body geldige JSON is — handig om de {code, message}-vorm
    # van ResponseHelper::error te verifiëren)
    jq_pass=true
    jq_fail_reason=""
    if [ -n "$jq_filter" ] && [ "$curl_exit" -eq 0 ]; then
        if ! echo "$body" | jq -e "$jq_filter" > /dev/null 2>&1; then
            jq_pass=false
            jq_fail_reason="body failed jq assertion: $jq_filter"
        fi
    fi

    if [ "$curl_exit" -ne 0 ]; then
        echo "<ul class='fail'>" >> $TESTHTML
        echo -e "${RED}✗ FAIL (curl exit $curl_exit)${NC} (${response_time}ms)"
    elif [ "$http_code" -eq "$expected_status" ] && [ "$json_valid" = true ] && [ "$jq_pass" = true ]; then
        echo -e "${GREEN}✓ PASS${NC} (${response_time}ms)"
        echo "<ul class='pass'>" >> $TESTHTML
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo "<ul class='fail'>" >> $TESTHTML
        if [ "$http_code" -ne "$expected_status" ]; then
            echo -e "${RED}✗ FAIL (expected status $expected_status, got $http_code)${NC} (${response_time}ms)"
        elif [ "$json_valid" = false ]; then
            echo -e "${RED}✗ FAIL (invalid JSON response)${NC} (${response_time}ms)"
        elif [ "$jq_pass" = false ]; then
            echo -e "${RED}✗ FAIL ($jq_fail_reason)${NC} (${response_time}ms)"
        fi
    fi

    echo "Request: $method $endpoint (Accept: $accept)"
    echo "<li><strong>Request</strong>: $method <a href=""$endpoint"">$endpoint</a> (Accept: $accept)</li>" >> $TESTHTML

    echo "Response code: $http_code"
    echo "<li><strong>Response code</strong>: $http_code (expected $expected_status)</li>" >> $TESTHTML

    echo "Response time: ${response_time0}ms (no cache) / ${response_time}ms (with cache)"
    echo "<li><strong>Response time</strong>: ${response_time0}ms (no cache) / ${response_time}ms (with cache)</li>" >> $TESTHTML

    if [ -n "$jq_filter" ]; then
        echo "<li><strong>Body assertion</strong>: <code>$jq_filter</code></li>" >> $TESTHTML
    fi

    body_preview="${body:0:250}"
    echo "Response body: $body_preview"
    echo "<li><strong>Response body</strong>: <xmp>$body_preview" >> $TESTHTML
    if [ ${#body} -gt 250 ]; then
        echo "..."
        echo "..." >> $TESTHTML
    fi
    echo "</xmp></li>" >> $TESTHTML

    echo "</ul>"  >> $TESTHTML
}

# Function to verify a specific response header matches a regex.
# Uses HEAD request; falls back if 405/HEAD not supported is observed.
test_header() {
    local method=$1
    local endpoint=$2
    local header_name=$3
    local expected_regex=$4
    local description=$5

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    echo -e "\n${YELLOW}Test $TOTAL_TESTS: $description${NC}"
    echo "<h3>Test $TOTAL_TESTS: $description</h3>" >> $TESTHTML

    start_time=$(date +%s%3N)
    headers=$(curl -sD - -o /dev/null -X $method "$endpoint")
    end_time=$(date +%s%3N)
    response_time=$((end_time - start_time))

    header_line=$(echo "$headers" | grep -i "^${header_name}:" | head -n1 | tr -d '\r')
    header_value=$(echo "$header_line" | sed -E "s/^[^:]+:[[:space:]]*//")

    if [[ "$header_value" =~ $expected_regex ]]; then
        echo -e "${GREEN}✓ PASS${NC} (${response_time}ms) — $header_name: $header_value"
        echo "<ul class='pass'>" >> $TESTHTML
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC} expected '$header_name' matching /$expected_regex/, got: '$header_value' (${response_time}ms)"
        echo "<ul class='fail'>" >> $TESTHTML
    fi

    echo "<li><strong>Request</strong>: $method <a href=""$endpoint"">$endpoint</a></li>" >> $TESTHTML
    echo "<li><strong>Header</strong>: $header_name: ${header_value:-<missing>}</li>" >> $TESTHTML
    echo "<li><strong>Expected pattern</strong>: <code>$expected_regex</code></li>" >> $TESTHTML
    echo "</ul>" >> $TESTHTML
}

echo -e "${CYAN}Starting API Tests...${NC}"
echo "Base URL: $BASE_URL"

# Clear cache before testing
echo -e "\n${CYAN}Clearing API cache...${NC}"
response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/clear_cache")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | head -n -1)

if [ "$http_code" -eq 200 ]; then
    echo -e "${GREEN}✓ Cache cleared successfully${NC}"
    echo "Response: $body"
else
    echo -e "${YELLOW}⚠ Cache clear returned status $http_code${NC}"
fi

# GET /straatnamen/{identifier}
print_test_header "GET /straatnamen/{identifier}"
test_json_endpoint "GET" "$BASE_URL/straatnamen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1Q" 200 "Geef informatie over de Lombardsteeg (https://n2t.net/ark:/60537/bn4b1Q); valideer geometry + alt_names" "application/json" '.identifier and .naam and .type and (.geometry.type | IN("LineString","MultiLineString","Polygon")) and (.alt_names | type == "array")'
test_json_endpoint "GET" "$BASE_URL/straatnamen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fb4N7Fm" 200 "Geef informatie over het Houtmanspad (https://n2t.net/ark:/60537/b4N7Fm); valideer dat problematisch-veld een niet-lege uitleg bevat" "application/json" '.identifier and .naam and (.problematisch | type == "string") and (.problematisch | length > 0)'
test_json_endpoint "GET" "$BASE_URL/straatnamen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1a" 404 "Geef informatie over een niet-bestaande straat"
test_json_endpoint "GET" "$BASE_URL/straatnamen/test" 400 "Geef informatie over straat op basis van een ongeldige identifier; foutbody bevat code+message" "application/json" '.code == "INVALID_IDENTIFIER" and (.message | type == "string")'
test_json_endpoint "GET" "$BASE_URL/straatnamen/https%3A%2F%2Fexample.com%2Ffoo" 400 "Identifier is geldige URL maar geen ARK (verwacht 400)"

# GET /straatnamen
print_test_header "GET /straatnamen"
test_json_endpoint "GET" "$BASE_URL/straatnamen" 200 "Geef een lijst van straten (in JSON)" "application/json" '.straten | length > 0'
test_json_endpoint "GET" "$BASE_URL/straatnamen?geojson" 200 "Geef lijst van alle straatnamen (in GeoJSON via query param)" "application/json" '.type == "FeatureCollection" and (.features | length > 0)'
test_json_endpoint "GET" "$BASE_URL/straatnamen" 200 "Geef lijst van alle straatnamen (in GeoJSON via Accept header)" "application/geo+json" '.type == "FeatureCollection" and (.features[0].geometry.type | IN("MultiLineString","LineString","Polygon"))'
test_json_endpoint "GET" "$BASE_URL/straatnamen?limit=10&offset=10" 200 "Geef lijst van 10 straatnamen (beginnende op index 10); aantal-veld matcht het aantal terugkomende rijen" "application/json" '(.straten | length == 10) and (.aantal | type == "number") and (.aantal == (.straten | length))'
test_json_endpoint "GET" "$BASE_URL/straatnamen?q=achter" 200 "Geef lijst van straatnamen met zoekterm 'achter'" "application/json" '.straten | length > 0 and all(any((.naam, (.naam_alt // [])[]); test("achter"; "i")))'
test_json_endpoint "GET" "$BASE_URL/straatnamen?q=xyz" 404 "Geef lijst van straatnamen met zoekterm 'xyz' die niet gevonden wordt"
test_json_endpoint "GET" "$BASE_URL/straatnamen?offset=999999" 404 "Offset voorbij beschikbare straten levert 404 met code NOT_FOUND" "application/json" '.code == "NOT_FOUND"'
test_json_endpoint "GET" "$BASE_URL/straatnamen?limit=5&lat=52.00497413812719&lon=4.678175320942639" 200 "Geef lijst van 5 straatnamen dicht bij punt" "application/json" '.straten | length == 5'
test_json_endpoint "GET" "$BASE_URL/straatnamen?type=huidig&limit=5" 200 "Filter type=huidig levert straten op" "application/json" '.straten | length > 0'
test_json_endpoint "GET" "$BASE_URL/straatnamen?type=verdwenen&limit=5" 200 "Filter type=verdwenen levert straten op" "application/json" '.straten | length > 0'
test_json_endpoint "GET" "$BASE_URL/straatnamen?type=onzin&limit=5" 404 "Onbekend type-filter passeert ongewijzigd naar SPARQL en levert 404"
test_json_endpoint "GET" "$BASE_URL/straatnamen?q=achter&type=huidig&limit=5" 200 "Gecombineerd filter (q + type=huidig) levert straten op" "application/json" '.straten | length > 0'
test_json_endpoint "GET" "$BASE_URL/straatnamen?q=xyz&geojson" 200 "GeoJSON-mode met onvindbare zoekterm levert lege FeatureCollection (geen 404)" "application/json" '.type == "FeatureCollection" and (.features | length == 0)'
test_json_endpoint "GET" "$BASE_URL/straatnamen?limit=5&lat=52.00497413812719&lon=4.678175320942639&geojson" 200 "GeoJSON-mode met lat/lon proximity" "application/json" '.type == "FeatureCollection" and (.features | length == 5)'
test_json_endpoint "GET" "$BASE_URL/straatnamen?limit=5" 406 "Ongeldige Accept header (text/plain) levert 406; foutbody bevat code NOT_ACCEPTABLE" "text/plain" '.code == "NOT_ACCEPTABLE" and (.message | type == "string")'

# GET /afbeeldingen/{identifier}
print_test_header "GET /afbeeldingen/{identifier}"
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1Q" 200 "Geef informatie over de Lombardsteeg (https://n2t.net/ark:/60537/bn4b1Q)" "application/json" '.afbeeldingen | length > 0 and (.[0] | has("identifier") and has("titel") and has("thumbnail"))'
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1Q?limit=3" 200 "Beperk afbeeldingen tot 3 met limit parameter en check aantal-totaal" "application/json" '(.afbeeldingen | length == 3) and (.aantal | type == "number") and (.aantal >= 3)'
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1Q?limit=5&offset=999999" 200 "Offset voorbij beschikbare afbeeldingen levert lege array" "application/json" '.afbeeldingen | length == 0'
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fbn4b1a" 404 "Geef informatie over een niet-bestaande straat"
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/test" 400 "Geef informatie over straat op basis van een ongeldige identifier"
test_json_endpoint "GET" "$BASE_URL/afbeeldingen/https%3A%2F%2Fexample.com%2Ffoo" 400 "Identifier is geldige URL maar geen ARK (verwacht 400)"

# GET /ping
print_test_header "GET /ping"
test_json_endpoint "GET" "$BASE_URL/ping" 200 "Ping diagnostics endpoint geeft 200 + verwachte sleutels + sparql endpoint heeft gereageerd" "application/json" 'has("time") and has("php") and has("egress_ipv4") and has("sparql_probe") and (.sparql_probe | has("url") and has("connect_ms") and (.http | type == "number"))'

# HTTP method handling
print_test_header "HTTP check - Methods"
test_endpoint "PUT" "$BASE_URL/straatnamen" 405 "PUT op /straatnamen levert 405 Method Not Allowed"
test_endpoint "DELETE" "$BASE_URL/straatnamen" 405 "DELETE op /straatnamen levert 405 Method Not Allowed"

# Static asset serving
print_test_header "HTTP check - Static assets"
test_header "GET" "$BASE_URL/assets/style.css" "Content-Type" "^text/css" "/assets/style.css wordt geserveerd als text/css"
test_header "GET" "$BASE_URL/assets/gtm-logo-2025.svg" "Content-Type" "^image/svg\+xml" "/assets/gtm-logo-2025.svg wordt geserveerd als image/svg+xml"
test_header "GET" "$BASE_URL/assets/style.css" "Cache-Control" "max-age" "/assets/* wordt met Cache-Control geserveerd"
test_header "GET" "$BASE_URL/favicon.ico" "Content-Type" "^image/x-icon" "/favicon.ico wordt geserveerd als image/x-icon"
test_endpoint "GET" "$BASE_URL/assets/../api/config.php" 200 "Path traversal poging valt door naar docs (realpath-guard in serveAsset)"
test_endpoint "GET" "$BASE_URL/qa-results/" 200 "/qa-results/ serveert testresultaten-HTML"

# CORS headers on real responses
print_test_header "HTTP check - CORS headers op GET"
test_header "GET" "$BASE_URL/straatnamen?limit=1" "Access-Control-Allow-Origin" "^\*$" "GET /straatnamen bevat Access-Control-Allow-Origin: *"
test_header "GET" "$BASE_URL/straatnamen?limit=1" "Access-Control-Allow-Methods" "GET" "GET /straatnamen bevat Access-Control-Allow-Methods met GET"
test_header "GET" "$BASE_URL/straatnamen?limit=1" "Access-Control-Allow-Headers" "Content-Type" "GET /straatnamen bevat Access-Control-Allow-Headers met Content-Type"
test_header "GET" "$BASE_URL/ping" "Content-Type" "^application/json" "GET /ping bevat Content-Type: application/json"
test_header "GET" "$BASE_URL/straatnamen?geojson&limit=1" "Content-Type" "^application/geo\+json" "GeoJSON-response heeft Content-Type: application/geo+json"


# ** HTTP check

# Test undefined route (should serve docs.html)
print_test_header "HTTP check - Documentatie"
test_endpoint "GET" "$BASE_URL/undefined-route" 200 "Ongedefineerde route moet leiden naar documentatie"
test_endpoint "GET" "$BASE_URL/straatnamen/" 200 "/straatnamen/ (lege identifier) matcht de detail-route niet en valt door naar documentatie"

#  OPTIONS request (CORS preflight, geldt globaal: short-circuit in index.php voor elk pad)
print_test_header "HTTP check - CORS"
test_endpoint "OPTIONS" "$BASE_URL/straatnamen" 204 "OPTIONS op bestaand pad /straatnamen levert 204"
test_endpoint "OPTIONS" "$BASE_URL/onbekend-pad" 204 "OPTIONS op onbekend pad levert ook 204 (globale CORS-preflight)"
test_header "OPTIONS" "$BASE_URL/straatnamen" "Access-Control-Allow-Origin" "^\*$" "OPTIONS-preflight bevat Access-Control-Allow-Origin: *"
test_header "OPTIONS" "$BASE_URL/straatnamen" "Access-Control-Allow-Methods" "GET, POST, OPTIONS" "OPTIONS-preflight bevat Access-Control-Allow-Methods: GET, POST, OPTIONS"
test_header "OPTIONS" "$BASE_URL/straatnamen" "Access-Control-Allow-Headers" "Content-Type" "OPTIONS-preflight bevat Access-Control-Allow-Headers: Content-Type"

# Test SAMH links
print_test_header "HTTP check - Test SAMH links"
test_json_endpoint "GET" "https://images.memorix.nl/sahm/iiif/c8a7c04c-a2b4-99cf-3999-18d6b6478563/info.json" 200 "IIIF Image API"
test_endpoint "GET" "https://samh.nl/bronnen/beeldbank/detail/0a6ffb0c-7959-70e8-d995-4bc3a1d0d9df/media/c8a7c04c-a2b4-99cf-3999-18d6b6478563" 200 "SAMH beeldbank"
test_endpoint "GET" "https://samh.nl/bronnen/genealogie/deeds/a634024a-cac3-98ba-3d4f-77a5e270a5ec" 200 "SAMH akte pagina"

# Test Omeka thumbnails
print_test_header "HTTP check - Test Omeka thumbnails"
test_endpoint "GET" "https://www.goudatijdmachine.nl/omeka/files/medium/05a057c0734aeb68e67b609a35473ec977521a1f.jpg" 200 "Omeka thumbnail 1 die bestaat" "no"
test_endpoint "GET" "https://www.goudatijdmachine.nl/omeka/files/medium/f166c8fbec016f567150983214a2c46e99177c1e.jpg" 404 "Omeka thumbnail 2 die niet bestaat" "no"

# POST /clear_cache (formele test - de warmup-call helemaal aan het begin telt niet mee in de resultaten)
print_test_header "Beheer - POST /clear_cache"
test_json_endpoint "POST" "$BASE_URL/clear_cache" 200 "POST /clear_cache zonder verse cache-inhoud levert 'No keys were cleared' (==0 branch)" "application/json" '.message | test("No keys were cleared")'
test_endpoint "GET" "$BASE_URL/clear_cache" 200 "GET /clear_cache valt op ongedefineerde route en levert documentatie"

# Cache >0 branch — we kunnen de standaard helper niet gebruiken want die roept elk
# endpoint twee keer aan (warmup + meting), wat altijd in de ==0 branch eindigt.
# Daarom hier een one-shot: vul de cache met één GET en clear daarna eenmalig.
print_test_header "Beheer - clear_cache >0 branch"
TOTAL_TESTS=$((TOTAL_TESTS + 1))
echo -e "\n${YELLOW}Test $TOTAL_TESTS: POST /clear_cache na cache vullen levert 'Successfully cleared N keys' (>0 branch)${NC}"
echo "<h3>Test $TOTAL_TESTS: POST /clear_cache na cache vullen levert 'Successfully cleared N keys' (>0 branch)</h3>" >> $TESTHTML
curl -s -H "Accept: application/json" "$BASE_URL/straatnamen?limit=1" > /dev/null
clear_body=$(curl -s -X POST "$BASE_URL/clear_cache")
if echo "$clear_body" | jq -e '.message | test("Successfully cleared [0-9]+ keys from cache")' > /dev/null 2>&1; then
    echo -e "${GREEN}✓ PASS${NC} — $clear_body"
    PASSED_TESTS=$((PASSED_TESTS + 1))
    echo "<ul class='pass'>" >> $TESTHTML
else
    echo -e "${RED}✗ FAIL${NC} — verwachtte 'Successfully cleared N keys from cache', kreeg: $clear_body"
    echo "<ul class='fail'>" >> $TESTHTML
fi
echo "<li><strong>Request</strong>: POST <a href=\"$BASE_URL/clear_cache\">$BASE_URL/clear_cache</a> (na 1 GET om cache te vullen)</li>" >> $TESTHTML
echo "<li><strong>Response body</strong>: <xmp>$clear_body</xmp></li>" >> $TESTHTML
echo "</ul>" >> $TESTHTML

# Summary
echo -e "\n${CYAN}===== Test Summary =====${NC}"
echo -e "Total Tests: $TOTAL_TESTS"
echo -e "Passed: ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed: ${RED}$((TOTAL_TESTS - PASSED_TESTS))${NC}"

echo -e "</body></html>" >> $TESTHTML

SUMFILE="summary.html"

if [ $PASSED_TESTS -eq $TOTAL_TESTS ]; then
    echo -e "\n${GREEN}🎉 All tests passed!${NC}"
    echo -e "<h2>Samenvatting: alle tests geslaagd</h2>" > $SUMFILE
else
    echo -e "\n${RED}❌ Some tests failed.${NC}"
    echo -e "<h2>Samenvatting: sommige tests gefaald</h2>" > $SUMFILE
fi

if [ $TOTAL_TESTS -eq $PASSED_TESTS ]; then
    echo "<ul class='pass'>" >> $SUMFILE
else
    echo "<ul class='fail'>" >> $SUMFILE
fi
echo "<li><strong>Basis URL</strong>: ${BASE_URL}" >> $SUMFILE
echo "<li><strong>Uitgevoerd</strong>: " >> $SUMFILE
echo $(TZ="Europe/Amsterdam" date +"%d-%m-%Y (%H:%M:%S)") >> $SUMFILE
echo "</li>" >> $SUMFILE
echo -e "<li><strong>Aantal tests</strong>: $TOTAL_TESTS</li>" >> $SUMFILE
echo -e "<li><strong>Geslaagd</strong>: $PASSED_TESTS</li>" >> $SUMFILE
echo -e "<li><strong>Gefaald</strong>: $((TOTAL_TESTS - PASSED_TESTS))</li></ul><p><br></p>" >> $SUMFILE

SUMMARY=$(awk '{printf "%s",$0}' $SUMFILE)
sed -i "s|<summary>|${SUMMARY}|" $TESTHTML

rm $SUMFILE

if [ $PASSED_TESTS -ne $TOTAL_TESTS ]; then
    exit 1
fi
