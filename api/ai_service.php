<?php
require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: ($_ENV['OPENROUTER_API_KEY'] ?? ''));
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini'); // fast + cheap
define('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('COMPONENTS_DATA_DIR', __DIR__ . '/../data/components');

// Image search fallback (DuckDuckGo lite – no API key needed)
define('IMAGE_SEARCH_ENABLED', true);

// Budget allocation presets (percentages that sum to 1.0)
define('BUDGET_ALLOCATIONS', [
    'gaming' => [
        'cpu' => 0.17, 'motherboard' => 0.11, 'ram' => 0.07, 'gpu' => 0.38,
        'storage' => 0.07, 'psu' => 0.06, 'case' => 0.02, 'cooler' => 0.03,
        'case-fan' => 0.02, 'keyboard' => 0.03, 'mouse' => 0.02
    ],
    'professional' => [
        'cpu' => 0.28, 'motherboard' => 0.13, 'ram' => 0.15, 'gpu' => 0.20,
        'storage' => 0.09, 'psu' => 0.07, 'case' => 0.02, 'cooler' => 0.04,
        'case-fan' => 0.02, 'keyboard' => 0.03, 'mouse' => 0.02
    ],
    'productivity' => [
        'cpu' => 0.22, 'motherboard' => 0.12, 'ram' => 0.12, 'gpu' => 0.22,
        'storage' => 0.10, 'psu' => 0.06, 'case' => 0.02, 'cooler' => 0.03,
        'case-fan' => 0.02, 'keyboard' => 0.04, 'mouse' => 0.03
    ],
    'streaming' => [
        'cpu' => 0.24, 'motherboard' => 0.12, 'ram' => 0.12, 'gpu' => 0.24,
        'storage' => 0.08, 'psu' => 0.06, 'case' => 0.02, 'cooler' => 0.04,
        'case-fan' => 0.02, 'keyboard' => 0.03, 'mouse' => 0.03
    ],
    'general' => [
        'cpu' => 0.20, 'motherboard' => 0.12, 'ram' => 0.09, 'gpu' => 0.33,
        'storage' => 0.08, 'psu' => 0.06, 'case' => 0.02, 'cooler' => 0.03,
        'case-fan' => 0.02, 'keyboard' => 0.03, 'mouse' => 0.02
    ],
]);

define('MINIMUM_BUILD_PRICES', [
    'gaming'       => 25000,
    'professional' => 35000,
    'productivity' => 20000,
    'streaming'    => 30000,
    'general'      => 18000,
]);

define('ESSENTIAL_COMPONENT_TYPES', [
    'cpu', 'motherboard', 'ram', 'gpu', 'storage', 'psu', 'case', 'cooler', 'case-fan', 'keyboard', 'mouse'
]);

// Keyword patterns for parsing
define('COMPONENT_TYPE_KEYWORDS', [
    'cpu'         => ['processor','cpu','core i3','core i5','core i7','core i9','ryzen 3','ryzen 5','ryzen 7','ryzen 9','intel','amd'],
    'gpu'         => ['graphics card','gpu','video card','graphics','vga','rtx','gtx','geforce','radeon','rx '],
    'ram'         => ['memory','ram','ddr4','ddr5','dimm'],
    'storage'     => ['storage','ssd','hard drive','nvme','hdd','m.2','solid state'],
    'motherboard' => ['motherboard','mainboard','mobo','board','chipset'],
    'psu'         => ['power supply','psu','wattage','watt','modular psu'],
    'case'        => ['case','chassis','tower','pc case','computer case'],
    'cooler'      => ['cooler','cooling','cpu cooler','aio','liquid cooling'],
    'monitor'     => ['monitor','display','screen','lcd','led'],
]);

define('PERFORMANCE_KEYWORDS', [
    'gaming'       => ['gaming','fps','1080p gaming','1440p gaming','4k gaming','esports','competitive','game'],
    'professional' => ['rendering','video editing','3d modeling','photoshop','premiere','blender','cad','autocad'],
    'productivity' => ['office work','multitasking','productivity','excel','programming','coding','school','work'],
    'streaming'    => ['streaming','twitch','obs','streaming setup','content creation','youtube'],
]);

define('PRICE_PATTERNS', [
    '/under\s+₱?\s*([\d,]+)/i',
    '/below\s+₱?\s*([\d,]+)/i',
    '/less\s+than\s+₱?\s*([\d,]+)/i',
    '/above\s+₱?\s*([\d,]+)/i',
    '/over\s+₱?\s*([\d,]+)/i',
    '/within\s+₱?\s*([\d,]+)/i',
    '/around\s+₱?\s*([\d,]+)/i',
    '/budget\s+(?:of\s+)?₱?\s*([\d,]+)/i',
    '/max(?:imum)?\s+₱?\s*([\d,]+)/i',
    '/₱\s*([\d,]+)/i',
    '/([\d,]+)\s*(?:pesos?|php)/i',
    '/(\d+)\s*k\s*(?:budget|pesos?|php|build)?/i',
    '/\b(\d{4,6})\b/',
]);

// ---------------------------------------------------------------------------
// Logging helper
// ---------------------------------------------------------------------------
function aiLog(string $msg, string $level = 'INFO'): void {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] [$level] $msg" . PHP_EOL;
    @file_put_contents(__DIR__ . '/../ai_service.log', $line, FILE_APPEND);
    error_log("[AI Service] $msg");
}

/**
 * Fix common JSON issues from AI responses:
 * - Remove commas in numbers (e.g., "price": 10,500 → "price": 10500)
 * - Strip markdown fences
 */
function fixAIJson(string $raw): ?array {
    // Strip markdown code fences
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/```\s*$/i', '', $raw);
    $raw = trim($raw);
    // Try parsing directly first
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) return $parsed;
    // Fix comma-separated numbers in JSON values (e.g., "price": 10,500 → "price": 10500)
    // This regex matches digits followed by comma-digit groups NOT inside quotes
    $fixed = preg_replace_callback('/:\s*(\d{1,3}(?:,\d{3})+)(?=[,\s\}])/', function($m) {
        return ': ' . str_replace(',', '', $m[1]);
    }, $raw);
    $parsed = json_decode($fixed, true);
    if (is_array($parsed)) return $parsed;
    // Last resort: try to extract JSON from surrounding text
    if (preg_match('/\{[\s\S]*\}/', $raw, $jsonMatch)) {
        $extracted = $jsonMatch[0];
        $extracted = preg_replace_callback('/:\s*(\d{1,3}(?:,\d{3})+)(?=[,\s\}])/', function($m) {
            return ': ' . str_replace(',', '', $m[1]);
        }, $extracted);
        $parsed = json_decode($extracted, true);
        if (is_array($parsed)) return $parsed;
    }
    return null;
}

// ---------------------------------------------------------------------------
// OpenRouter helpers
// ---------------------------------------------------------------------------

/**
 * Call the OpenRouter chat completion API.
 * @param array $messages  Chat messages in OpenAI format [{role, content}, ...]
 * @param float $temperature
 * @param int   $maxTokens
 * @return string  The assistant's reply text
 */
function callOpenRouter(array $messages, float $temperature = 0.7, int $maxTokens = 2048): string {
    $apiKey = OPENROUTER_API_KEY;
    if (empty($apiKey)) {
        aiLog('OPENROUTER_API_KEY is not set!', 'ERROR');
        return 'I apologize, but the AI service is not properly configured. Please contact the administrator.';
    }
    $payload = [
        'model'       => OPENROUTER_MODEL,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ];
    $ch = curl_init(OPENROUTER_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost',
            'X-Title: SmartSpecs PC Builder',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        aiLog("OpenRouter cURL error: $curlErr", 'ERROR');
        return 'Sorry, I had trouble connecting to the AI service. Please try again.';
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? $response;
        aiLog("OpenRouter HTTP $httpCode: $errMsg", 'ERROR');
        return 'Sorry, the AI service returned an error. Please try again later.';
    }
    return $data['choices'][0]['message']['content'] ?? 'I wasn\'t able to generate a response. Please try again.';
}

// ---------------------------------------------------------------------------
// Geolocation & Regional Store Helpers
// ---------------------------------------------------------------------------

/**
 * Detect the user's country based on their IP address.
 * Uses the free ip-api.com service (no API key needed).
 */
function getUserLocation(): array {
    $default = [
        'country'      => 'Philippines',
        'country_code' => 'PH',
        'city'         => 'Manila',
        'currency'     => 'PHP',
        'currency_symbol' => '₱',
    ];
    // Get the real client IP
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '127.0.0.1';
    // Take the first IP if there are multiple (X-Forwarded-For can be comma-separated)
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    // localhost / private IP → fall back to default
    if (in_array($ip, ['127.0.0.1', '::1', '']) || preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $ip)) {
        aiLog("Local/private IP detected ($ip), using default location: Philippines");
        return $default;
    }
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,currency");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$response) {
        aiLog("Geolocation failed: $err", 'WARN');
        return $default;
    }
    $data = json_decode($response, true);
    if (!$data || ($data['status'] ?? '') !== 'success') {
        aiLog("Geolocation returned non-success for IP $ip", 'WARN');
        return $default;
    }
    $countryCode = $data['countryCode'] ?? 'PH';
    $currencyMap = [
        // Asia
        'PH' => ['PHP', '₱'], 'JP' => ['JPY', '¥'], 'KR' => ['KRW', '₩'],
        'SG' => ['SGD', 'S$'], 'MY' => ['MYR', 'RM'], 'TH' => ['THB', '฿'],
        'IN' => ['INR', '₹'], 'ID' => ['IDR', 'Rp'], 'VN' => ['VND', '₫'],
        'TW' => ['TWD', 'NT$'], 'HK' => ['HKD', 'HK$'], 'CN' => ['CNY', '¥'],
        'BD' => ['BDT', '৳'], 'PK' => ['PKR', '₨'], 'NP' => ['NPR', '₨'],
        'LK' => ['LKR', '₨'], 'KH' => ['KHR', '៛'], 'BN' => ['BND', 'B$'],
        'AZ' => ['AZN', '₼'], 'GE' => ['GEL', '₾'], 'KZ' => ['KZT', '₸'],
        'UZ' => ['UZS', 'сўм'],
        // Europe
        'GB' => ['GBP', '£'], 'DE' => ['EUR', '€'], 'FR' => ['EUR', '€'],
        'IT' => ['EUR', '€'], 'ES' => ['EUR', '€'], 'NL' => ['EUR', '€'],
        'AT' => ['EUR', '€'], 'BE' => ['EUR', '€'], 'FI' => ['EUR', '€'],
        'IE' => ['EUR', '€'], 'PT' => ['EUR', '€'], 'GR' => ['EUR', '€'],
        'CY' => ['EUR', '€'], 'MT' => ['EUR', '€'], 'SK' => ['EUR', '€'],
        'SI' => ['EUR', '€'], 'EE' => ['EUR', '€'], 'LV' => ['EUR', '€'],
        'LT' => ['EUR', '€'], 'HR' => ['EUR', '€'], 'BG' => ['BGN', 'лв'],
        'RO' => ['RON', 'lei'], 'PL' => ['PLN', 'zł'], 'CZ' => ['CZK', 'Kč'],
        'HU' => ['HUF', 'Ft'], 'DK' => ['DKK', 'kr'], 'SE' => ['SEK', 'kr'],
        'NO' => ['NOK', 'kr'], 'CH' => ['CHF', 'CHF'], 'RS' => ['RSD', 'din'],
        'BA' => ['BAM', 'KM'], 'AL' => ['ALL', 'L'], 'MK' => ['MKD', 'ден'],
        'MD' => ['MDL', 'L'], 'UA' => ['UAH', '₴'], 'RU' => ['RUB', '₽'],
        'XK' => ['EUR', '€'],
        // Americas
        'US' => ['USD', '$'], 'CA' => ['CAD', 'C$'], 'BR' => ['BRL', 'R$'],
        'MX' => ['MXN', 'MX$'], 'AR' => ['ARS', 'AR$'], 'CL' => ['CLP', 'CL$'],
        'CO' => ['COP', 'COL$'], 'PE' => ['PEN', 'S/'], 'UY' => ['UYU', '$U'],
        'VE' => ['VES', 'Bs'], 'BO' => ['BOB', 'Bs'], 'PY' => ['PYG', '₲'],
        'EC' => ['USD', '$'], 'PA' => ['USD', '$'], 'CR' => ['CRC', '₡'],
        'DO' => ['DOP', 'RD$'], 'GT' => ['GTQ', 'Q'], 'SV' => ['USD', '$'],
        'NI' => ['NIO', 'C$'], 'PR' => ['USD', '$'], 'TT' => ['TTD', 'TT$'],
        // Oceania
        'AU' => ['AUD', 'A$'], 'NZ' => ['NZD', 'NZ$'],
        // Africa
        'ZA' => ['ZAR', 'R'], 'EG' => ['EGP', 'E£'], 'DZ' => ['DZD', 'د.ج'],
        'MA' => ['MAD', 'د.م.'], 'TN' => ['TND', 'د.ت'], 'MU' => ['MUR', '₨'],
        // Middle East
        'AE' => ['AED', 'د.إ'], 'SA' => ['SAR', '﷼'], 'TR' => ['TRY', '₺'],
        'IL' => ['ILS', '₪'], 'QA' => ['QAR', '﷼'], 'KW' => ['KWD', 'د.ك'],
        'BH' => ['BHD', '.د.ب'], 'OM' => ['OMR', '﷼'], 'JO' => ['JOD', 'د.ا'],
        'LB' => ['LBP', 'ل.ل'], 'IQ' => ['IQD', 'ع.د'], 'IR' => ['IRR', '﷼'],
        'PS' => ['ILS', '₪'],
    ];
    $pair = $currencyMap[$countryCode] ?? ['USD', '$'];
    return [
        'country'         => $data['country'] ?? 'Philippines',
        'country_code'    => $countryCode,
        'city'            => $data['city'] ?? '',
        'currency'        => $pair[0],
        'currency_symbol' => $pair[1],
    ];
}

/**
 * Return relevant online stores for a given country.
 */
function getRegionalStores(string $countryCode): array {
    $stores = [
        // ===================== AFRICA =====================
        'DZ' => [ // Algeria
            ['name' => 'WifiDjelfa',     'domain' => 'wifidjelfa.com',       'search_url' => 'https://wifidjelfa.com/?s={query}'],
            ['name' => 'GeekZone DZ',    'domain' => 'geekzonedz.com',       'search_url' => 'https://www.geekzonedz.com/?s={query}'],
            ['name' => 'Fractal Shop',   'domain' => 'fractal-shop.com',     'search_url' => 'https://fractal-shop.com/?s={query}'],
            ['name' => 'CHB Store',      'domain' => 'chb-store.com',        'search_url' => 'https://chb-store.com/?s={query}'],
        ],
        'EG' => [ // Egypt
            ['name' => 'Sigma Computer', 'domain' => 'sigma-computer.com',   'search_url' => 'https://www.sigma-computer.com/search?search={query}'],
            ['name' => 'Hankerz',        'domain' => 'hankerz.com.eg',       'search_url' => 'https://www.hankerz.com.eg/search?q={query}'],
            ['name' => 'CompuMarts',     'domain' => 'compumarts.com',       'search_url' => 'https://www.compumarts.com/search?q={query}'],
            ['name' => 'EG Prices',      'domain' => 'egprices.com',         'search_url' => 'https://www.egprices.com/en/search/{query}'],
        ],
        'MU' => [ // Mauritius
            ['name' => 'FastClick',      'domain' => 'fastclick.mu',         'search_url' => 'https://fastclick.mu/?s={query}'],
            ['name' => 'Fjaril Tech',    'domain' => 'fjariltech.com',       'search_url' => 'https://www.fjariltech.com/?s={query}'],
            ['name' => 'Blink MU',       'domain' => 'blink.mu',             'search_url' => 'https://blink.mu/search?q={query}'],
            ['name' => 'CompuSpeed',     'domain' => 'compuspeed.mu',        'search_url' => 'https://www.compuspeed.mu/?s={query}'],
        ],
        'MA' => [ // Morocco
            ['name' => 'UltraPC',        'domain' => 'ultrapc.ma',           'search_url' => 'https://www.ultrapc.ma/recherche?controller=search&s={query}'],
            ['name' => 'NextLevel PC',   'domain' => 'nextlevelpc.ma',       'search_url' => 'https://nextlevelpc.ma/?s={query}'],
            ['name' => 'TechSpace',      'domain' => 'techspace.ma',         'search_url' => 'https://techspace.ma/?s={query}'],
        ],
        'ZA' => [ // South Africa
            ['name' => 'Wootware',       'domain' => 'wootware.co.za',       'search_url' => 'https://www.wootware.co.za/catalogsearch/result/?q={query}'],
            ['name' => 'Evetech',        'domain' => 'evetech.co.za',        'search_url' => 'https://www.evetech.co.za/search.aspx?search={query}'],
            ['name' => 'Takealot',       'domain' => 'takealot.com',         'search_url' => 'https://www.takealot.com/all?qsearch={query}'],
            ['name' => 'Titan Ice',      'domain' => 'titan-ice.co.za',      'search_url' => 'https://www.titan-ice.co.za/search?q={query}'],
            ['name' => 'Incredible',     'domain' => 'incredible.co.za',     'search_url' => 'https://www.incredible.co.za/catalogsearch/result/?q={query}'],
            ['name' => 'Matrix Warehouse','domain' => 'matrixwarehouse.co.za','search_url' => 'https://matrixwarehouse.co.za/search?q={query}'],
        ],
        'TN' => [ // Tunisia
            ['name' => 'TunisiaNet',     'domain' => 'tunisianet.com.tn',    'search_url' => 'https://www.tunisianet.com.tn/recherche?controller=search&s={query}'],
            ['name' => 'MegaPC',         'domain' => 'megapc.tn',            'search_url' => 'https://megapc.tn/recherche?controller=search&s={query}'],
            ['name' => 'SBS Info',       'domain' => 'sbsinformatique.com',   'search_url' => 'https://www.sbsinformatique.com/recherche?controller=search&s={query}'],
            ['name' => 'SpaceNet',       'domain' => 'spacenet.tn',          'search_url' => 'https://spacenet.tn/recherche?controller=search&s={query}'],
            ['name' => 'Mytek',          'domain' => 'mytek.tn',             'search_url' => 'https://www.mytek.tn/catalogsearch/result/?q={query}'],
        ],

        // ===================== ASIA =====================
        'AZ' => [ // Azerbaijan
            ['name' => 'CompStore',      'domain' => 'compstore.az',         'search_url' => 'https://compstore.az/search?q={query}'],
            ['name' => 'AmazonComp',     'domain' => 'amazoncomp.az',        'search_url' => 'https://amazoncomp.az/search?q={query}'],
        ],
        'BD' => [ // Bangladesh
            ['name' => 'StarTech',       'domain' => 'startech.com.bd',      'search_url' => 'https://www.startech.com.bd/product/search?search={query}'],
            ['name' => 'Ryans',          'domain' => 'ryanscomputers.com',   'search_url' => 'https://www.ryanscomputers.com/search?q={query}'],
            ['name' => 'TechLand BD',    'domain' => 'techlandbd.com',       'search_url' => 'https://www.techlandbd.com/index.php?route=product/search&search={query}'],
            ['name' => 'Skyland',        'domain' => 'skyland.com.bd',       'search_url' => 'https://www.skyland.com.bd/?s={query}'],
            ['name' => 'UltraTech',      'domain' => 'ultratech.com.bd',     'search_url' => 'https://www.ultratech.com.bd/index.php?route=product/search&search={query}'],
            ['name' => 'PC House BD',    'domain' => 'pchouse.com.bd',       'search_url' => 'https://www.pchouse.com.bd/index.php?route=product/search&search={query}'],
        ],
        'BN' => [ // Brunei
            ['name' => 'JLite BN',       'domain' => 'jlitebn.com',          'search_url' => 'https://jlitebn.com/?s={query}'],
        ],
        'KH' => [ // Cambodia
            ['name' => 'Gold One',       'domain' => 'goldonecomputer.com',  'search_url' => 'https://www.goldonecomputer.com/?s={query}'],
        ],
        'CN' => [ // China
            ['name' => 'JD.com',         'domain' => 'jd.com',               'search_url' => 'https://search.jd.com/Search?keyword={query}'],
            ['name' => 'AliExpress',     'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'GE' => [ // Georgia
            ['name' => 'Gamers GE',      'domain' => 'gamers.ge',            'search_url' => 'https://gamers.ge/search?q={query}'],
            ['name' => 'Extra GE',       'domain' => 'extra.ge',             'search_url' => 'https://extra.ge/search/{query}'],
            ['name' => 'Ultra GE',       'domain' => 'ultra.ge',             'search_url' => 'https://ultra.ge/search/{query}'],
        ],
        'HK' => [ // Hong Kong
            ['name' => 'Price HK',       'domain' => 'price.com.hk',         'search_url' => 'https://www.price.com.hk/search.php?g=A&q={query}'],
            ['name' => 'Central Field',  'domain' => 'centralfield.com',     'search_url' => 'https://www.centralfield.com/search?q={query}'],
            ['name' => 'BuyMore',        'domain' => 'buymore.hk',           'search_url' => 'https://buymore.hk/shop/?s={query}'],
            ['name' => 'AliExpress',     'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'IN' => [ // India
            ['name' => 'Amazon IN',      'domain' => 'amazon.in',            'search_url' => 'https://www.amazon.in/s?k={query}'],
            ['name' => 'Flipkart',       'domain' => 'flipkart.com',         'search_url' => 'https://www.flipkart.com/search?q={query}'],
            ['name' => 'MD Computers',   'domain' => 'mdcomputers.in',       'search_url' => 'https://www.mdcomputers.in/search?search={query}'],
            ['name' => 'PCPriceTracker', 'domain' => 'pcpricetracker.in',    'search_url' => 'https://pcpricetracker.in/gen/products/{query}'],
            ['name' => 'EZPZSolutions',  'domain' => 'ezpzsolutions.in',     'search_url' => 'https://www.ezpzsolutions.in/search?q={query}'],
        ],
        'ID' => [ // Indonesia
            ['name' => 'EnterKomputer', 'domain' => 'enterkomputer.com',    'search_url' => 'https://www.enterkomputer.com/search?q={query}'],
            ['name' => 'Tokopedia',     'domain' => 'tokopedia.com',        'search_url' => 'https://www.tokopedia.com/search?st=product&q={query}'],
            ['name' => 'Shopee ID',     'domain' => 'shopee.co.id',         'search_url' => 'https://shopee.co.id/search?keyword={query}'],
            ['name' => 'AliExpress',    'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'JP' => [ // Japan
            ['name' => 'Kakaku',        'domain' => 'kakaku.com',           'search_url' => 'https://kakaku.com/search_results/{query}/'],
            ['name' => 'Amazon JP',     'domain' => 'amazon.co.jp',         'search_url' => 'https://www.amazon.co.jp/s?k={query}'],
            ['name' => 'Dospara',       'domain' => 'dospara.co.jp',        'search_url' => 'https://www.dospara.co.jp/SBR/{query}'],
            ['name' => 'Tsukumo',       'domain' => 'shop.tsukumo.co.jp',   'search_url' => 'https://shop.tsukumo.co.jp/search/?keyword={query}'],
            ['name' => 'Ark PC',        'domain' => 'ark-pc.co.jp',         'search_url' => 'https://www.ark-pc.co.jp/search/?q={query}'],
        ],
        'KZ' => [ // Kazakhstan
            ['name' => 'Kaspi',         'domain' => 'kaspi.kz',             'search_url' => 'https://kaspi.kz/shop/search/?text={query}'],
            ['name' => 'Technodom',     'domain' => 'technodom.kz',         'search_url' => 'https://www.technodom.kz/catalog/search?q={query}'],
            ['name' => 'DNS KZ',        'domain' => 'dns-shop.kz',          'search_url' => 'https://www.dns-shop.kz/search/?q={query}'],
        ],
        'MY' => [ // Malaysia
            ['name' => 'IdealTech',     'domain' => 'idealtech.com.my',     'search_url' => 'https://idealtech.com.my/search?q={query}'],
            ['name' => 'TMT',           'domain' => 'tmt.my',               'search_url' => 'https://www.tmt.my/search?q={query}'],
            ['name' => 'Shopee MY',     'domain' => 'shopee.com.my',        'search_url' => 'https://shopee.com.my/search?keyword={query}'],
            ['name' => 'Lazada MY',     'domain' => 'lazada.com.my',        'search_url' => 'https://www.lazada.com.my/catalog/?q={query}'],
            ['name' => 'AliExpress',    'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'NP' => [ // Nepal
            ['name' => 'HimmCom',       'domain' => 'himmcom.com.np',       'search_url' => 'https://himmcom.com.np/?s={query}'],
        ],
        'PK' => [ // Pakistan
            ['name' => 'CZone',         'domain' => 'czone.com.pk',         'search_url' => 'https://www.czone.com.pk/search?q={query}'],
            ['name' => 'Telemart',      'domain' => 'telemart.pk',          'search_url' => 'https://www.telemart.pk/search?q={query}'],
            ['name' => 'PakDukaan',     'domain' => 'pakdukaan.com',        'search_url' => 'https://www.pakdukaan.com/search?q={query}'],
        ],
        'PH' => [ // Philippines
            ['name' => 'EasyPC',        'domain' => 'easypc.com.ph',        'search_url' => 'https://easypc.com.ph/collections/all?q={query}'],
            ['name' => 'DynaQuest',     'domain' => 'dynaquestpc.com',      'search_url' => 'https://dynaquestpc.com/search?q={query}'],
            ['name' => 'PC Express',    'domain' => 'pcx.com.ph',           'search_url' => 'https://pcx.com.ph/search?q={query}'],
            ['name' => 'Villman',       'domain' => 'villman.com',           'search_url' => 'https://villman.com/Product-Search?search_value={query}'],
            ['name' => 'Bermorzone',    'domain' => 'bermorzone.com.ph',    'search_url' => 'https://bermorzone.com.ph/?s={query}'],
            ['name' => 'DataBlitz',     'domain' => 'datablitz.com.ph',     'search_url' => 'https://ecommerce.datablitz.com.ph/search?q={query}'],
            ['name' => 'Lazada PH',     'domain' => 'lazada.com.ph',        'search_url' => 'https://www.lazada.com.ph/catalog/?q={query}'],
            ['name' => 'Shopee PH',     'domain' => 'shopee.ph',            'search_url' => 'https://shopee.ph/search?keyword={query}'],
        ],
        'SG' => [ // Singapore
            ['name' => 'Dynacore',      'domain' => 'dynacoretech.com',     'search_url' => 'https://dynacoretech.com/?s={query}'],
            ['name' => 'Amazon SG',     'domain' => 'amazon.sg',            'search_url' => 'https://www.amazon.sg/s?k={query}'],
            ['name' => 'Shopee SG',     'domain' => 'shopee.sg',            'search_url' => 'https://shopee.sg/search?keyword={query}'],
            ['name' => 'Lazada SG',     'domain' => 'lazada.sg',            'search_url' => 'https://www.lazada.sg/catalog/?q={query}'],
            ['name' => 'AliExpress',    'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'KR' => [ // South Korea
            ['name' => 'Danawa',        'domain' => 'danawa.com',           'search_url' => 'https://search.danawa.com/dsearch.php?query={query}'],
            ['name' => 'Coupang',       'domain' => 'coupang.com',          'search_url' => 'https://www.coupang.com/np/search?component=&q={query}'],
            ['name' => 'CompuZone',     'domain' => 'compuzone.co.kr',      'search_url' => 'https://www.compuzone.co.kr/search/search.htm?SearchWord={query}'],
        ],
        'LK' => [ // Sri Lanka
            ['name' => 'GameStreet',    'domain' => 'gamestreet.lk',        'search_url' => 'https://www.gamestreet.lk/?s={query}'],
        ],
        'TW' => [ // Taiwan
            ['name' => 'CoolPC',        'domain' => 'coolpc.com.tw',        'search_url' => 'https://www.coolpc.com.tw/evaluate.php'],
            ['name' => 'AliExpress',    'domain' => 'aliexpress.com',       'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
        ],
        'TH' => [ // Thailand
            ['name' => 'Advice',        'domain' => 'advice.co.th',         'search_url' => 'https://www.advice.co.th/search/{query}'],
            ['name' => 'JIB',           'domain' => 'jib.co.th',            'search_url' => 'https://www.jib.co.th/web/product/product_search?str_search={query}'],
            ['name' => 'Invade IT',     'domain' => 'invadeit.co.th',       'search_url' => 'https://www.invadeit.co.th/search?q={query}'],
            ['name' => 'Shopee TH',     'domain' => 'shopee.co.th',         'search_url' => 'https://shopee.co.th/search?keyword={query}'],
            ['name' => 'Lazada TH',     'domain' => 'lazada.co.th',         'search_url' => 'https://www.lazada.co.th/catalog/?q={query}'],
        ],
        'VN' => [ // Vietnam
            ['name' => 'Phong Vu',      'domain' => 'phongvu.vn',           'search_url' => 'https://phongvu.vn/search?q={query}'],
            ['name' => 'GearVN',        'domain' => 'gearvn.com',           'search_url' => 'https://gearvn.com/search?q={query}'],
            ['name' => 'Hoang Ha PC',   'domain' => 'hoanghapc.vn',         'search_url' => 'https://hoanghapc.vn/tim?q={query}'],
            ['name' => 'Ha Noi Computer','domain' => 'hanoicomputer.vn',    'search_url' => 'https://www.hanoicomputer.vn/search?q={query}'],
            ['name' => 'Shopee VN',     'domain' => 'shopee.vn',            'search_url' => 'https://shopee.vn/search?keyword={query}'],
        ],
        'UZ' => [ // Uzbekistan
            ['name' => 'OLX UZ',        'domain' => 'olx.uz',               'search_url' => 'https://www.olx.uz/d/q-{query}/'],
        ],

        // ===================== EUROPE =====================
        'AL' => [ // Albania
            ['name' => 'AlbaGame',      'domain' => 'albagame.al',          'search_url' => 'https://www.albagame.al/?s={query}'],
            ['name' => 'PC Store AL',   'domain' => 'pcstore.al',           'search_url' => 'https://pcstore.al/?s={query}'],
        ],
        'AT' => [ // Austria
            ['name' => 'Geizhals',      'domain' => 'geizhals.eu',          'search_url' => 'https://geizhals.eu/?fs={query}'],
            ['name' => 'E-Tec',         'domain' => 'e-tec.at',             'search_url' => 'https://www.e-tec.at/search?q={query}'],
            ['name' => 'MediaMarkt AT', 'domain' => 'mediamarkt.at',        'search_url' => 'https://www.mediamarkt.at/de/search.html?query={query}'],
            ['name' => 'ProShop AT',    'domain' => 'proshop.at',           'search_url' => 'https://www.proshop.at/Search?search={query}'],
        ],
        'BE' => [ // Belgium
            ['name' => 'Tweakers',      'domain' => 'tweakers.net',         'search_url' => 'https://tweakers.net/zoeken/?query={query}'],
            ['name' => 'Bol.com BE',    'domain' => 'bol.com',              'search_url' => 'https://www.bol.com/be/nl/s/?searchtext={query}'],
        ],
        'BA' => [ // Bosnia & Herzegovina
            ['name' => 'Doper',         'domain' => 'doper.ba',             'search_url' => 'https://doper.ba/?s={query}'],
            ['name' => 'ProComp',       'domain' => 'procomp.ba',           'search_url' => 'https://procomp.ba/?s={query}'],
        ],
        'BG' => [ // Bulgaria
            ['name' => 'Ozone BG',      'domain' => 'ozone.bg',             'search_url' => 'https://www.ozone.bg/search/?q={query}'],
            ['name' => 'Ardes BG',      'domain' => 'ardes.bg',             'search_url' => 'https://ardes.bg/search/{query}'],
            ['name' => 'eMAG BG',       'domain' => 'emag.bg',              'search_url' => 'https://www.emag.bg/search/{query}'],
            ['name' => 'Plasico',       'domain' => 'plasico.bg',           'search_url' => 'https://plasico.bg/?search={query}'],
        ],
        'HR' => [ // Croatia
            ['name' => 'Futura IT',     'domain' => 'futura-it.hr',         'search_url' => 'https://www.futura-it.hr/pretraga/{query}/'],
            ['name' => 'Nabava',        'domain' => 'nabava.net',           'search_url' => 'https://www.nabava.net/search?q={query}'],
        ],
        'CY' => [ // Cyprus
            ['name' => 'Needy Shop',    'domain' => 'needy.shop',           'search_url' => 'https://needy.shop/?s={query}'],
            ['name' => 'Armenius',      'domain' => 'armenius.com.cy',      'search_url' => 'https://armenius.com.cy/?s={query}'],
        ],
        'CZ' => [ // Czech Republic
            ['name' => 'Heureka CZ',    'domain' => 'heureka.cz',           'search_url' => 'https://www.heureka.cz/?h%5Bfraze%5D={query}'],
            ['name' => 'CZC',           'domain' => 'czc.cz',               'search_url' => 'https://www.czc.cz/hledani?q={query}'],
            ['name' => 'Mironet',       'domain' => 'mironet.cz',           'search_url' => 'https://www.mironet.cz/hledani/?search={query}'],
        ],
        'DK' => [ // Denmark
            ['name' => 'ProShop DK',    'domain' => 'proshop.dk',           'search_url' => 'https://www.proshop.dk/Search?search={query}'],
            ['name' => 'Komplett DK',   'domain' => 'komplett.dk',          'search_url' => 'https://www.komplett.dk/search?q={query}'],
            ['name' => 'Elgiganten DK', 'domain' => 'elgiganten.dk',        'search_url' => 'https://www.elgiganten.dk/search?search={query}'],
        ],
        'EE' => [ // Estonia
            ['name' => 'ArvutiTark',    'domain' => 'arvutitark.ee',        'search_url' => 'https://arvutitark.ee/search?q={query}'],
            ['name' => 'DataGate',      'domain' => 'datagate.ee',          'search_url' => 'https://datagate.ee/search?q={query}'],
            ['name' => 'OnOff EE',      'domain' => 'onoff.ee',             'search_url' => 'https://onoff.ee/en/search?q={query}'],
        ],
        'FI' => [ // Finland
            ['name' => 'Verkkokauppa',  'domain' => 'verkkokauppa.com',     'search_url' => 'https://www.verkkokauppa.com/fi/search/{query}'],
            ['name' => 'Jimms',         'domain' => 'jimms.fi',             'search_url' => 'https://www.jimms.fi/fi/Product/Search?q={query}'],
            ['name' => 'ProShop FI',    'domain' => 'proshop.fi',           'search_url' => 'https://www.proshop.fi/Search?search={query}'],
        ],
        'FR' => [ // France
            ['name' => 'Idealo FR',     'domain' => 'idealo.fr',            'search_url' => 'https://www.idealo.fr/prechcomp/{query}'],
            ['name' => 'RueDuCommerce', 'domain' => 'rueducommerce.fr',     'search_url' => 'https://www.rueducommerce.fr/r/{query}.html'],
        ],
        'DE' => [ // Germany
            ['name' => 'Idealo DE',     'domain' => 'idealo.de',            'search_url' => 'https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q={query}'],
            ['name' => 'Geizhals',      'domain' => 'geizhals.eu',          'search_url' => 'https://geizhals.eu/?fs={query}'],
            ['name' => 'MediaMarkt DE', 'domain' => 'mediamarkt.de',        'search_url' => 'https://www.mediamarkt.de/de/search.html?query={query}'],
            ['name' => 'ProShop DE',    'domain' => 'proshop.de',           'search_url' => 'https://www.proshop.de/Search?search={query}'],
        ],
        'GR' => [ // Greece
            ['name' => 'Skroutz',       'domain' => 'skroutz.gr',           'search_url' => 'https://www.skroutz.gr/search?keyphrase={query}'],
            ['name' => 'Plaisio',       'domain' => 'plaisio.gr',           'search_url' => 'https://www.plaisio.gr/search?q={query}'],
        ],
        'HU' => [ // Hungary
            ['name' => 'Arukereso',     'domain' => 'arukereso.hu',         'search_url' => 'https://www.arukereso.hu/CategorySearch.php?st={query}'],
            ['name' => 'ipon',          'domain' => 'ipon.hu',              'search_url' => 'https://ipon.hu/kereso?search={query}'],
            ['name' => 'MediaMarkt HU', 'domain' => 'mediamarkt.hu',        'search_url' => 'https://www.mediamarkt.hu/hu/search.html?query={query}'],
        ],
        'IT' => [ // Italy
            ['name' => 'PSK Megastore', 'domain' => 'pskmegastore.com',     'search_url' => 'https://pskmegastore.com/search?q={query}'],
            ['name' => 'BPM Power',     'domain' => 'bpm-power.com',        'search_url' => 'https://www.bpm-power.com/search?q={query}'],
        ],
        'XK' => [ // Kosovo
            ['name' => 'Gjirafa50',     'domain' => 'gjirafa50.com',        'search_url' => 'https://gjirafa50.com/search?q={query}'],
        ],
        'LV' => [ // Latvia
            ['name' => 'MaxTrade',      'domain' => 'maxtrade.lv',          'search_url' => 'https://maxtrade.lv/search?q={query}'],
            ['name' => 'Dateks',        'domain' => 'dateks.lv',            'search_url' => 'https://dateks.lv/meklet?q={query}'],
            ['name' => '1a.lv',         'domain' => '1a.lv',                'search_url' => 'https://www.1a.lv/search?q={query}'],
        ],
        'LT' => [ // Lithuania
            ['name' => 'Pigu',          'domain' => 'pigu.lt',              'search_url' => 'https://pigu.lt/lt/paieska?q={query}'],
            ['name' => 'SkyTech',       'domain' => 'skytech.lt',           'search_url' => 'https://skytech.lt/search?q={query}'],
            ['name' => 'Kilobaitas',    'domain' => 'kilobaitas.lt',        'search_url' => 'https://www.kilobaitas.lt/paieska?q={query}'],
        ],
        'MK' => [ // North Macedonia
            ['name' => 'Anhoch',        'domain' => 'anhoch.com',           'search_url' => 'http://www.anhoch.com/search?q={query}'],
            ['name' => 'DD Store',      'domain' => 'ddstore.mk',           'search_url' => 'https://ddstore.mk/search?q={query}'],
        ],
        'MT' => [ // Malta
            ['name' => 'SimarkSupplies','domain' => 'simarksupplies.com',   'search_url' => 'https://www.simarksupplies.com/?s={query}'],
            ['name' => 'PCWise Malta',  'domain' => 'pcwisemalta.com',      'search_url' => 'https://pcwisemalta.com/?s={query}'],
        ],
        'MD' => [ // Moldova
            ['name' => 'NeoComputer',   'domain' => 'neocomputer.md',       'search_url' => 'https://neocomputer.md/search?q={query}'],
            ['name' => 'Darwin MD',     'domain' => 'darwin.md',            'search_url' => 'https://darwin.md/search?q={query}'],
        ],
        'NL' => [ // Netherlands
            ['name' => 'Tweakers',      'domain' => 'tweakers.net',         'search_url' => 'https://tweakers.net/zoeken/?query={query}'],
            ['name' => 'Bol.com',       'domain' => 'bol.com',              'search_url' => 'https://www.bol.com/nl/nl/s/?searchtext={query}'],
        ],
        'NO' => [ // Norway
            ['name' => 'Komplett NO',   'domain' => 'komplett.no',          'search_url' => 'https://www.komplett.no/search?q={query}'],
            ['name' => 'Elkjop',        'domain' => 'elkjop.no',            'search_url' => 'https://www.elkjop.no/search/{query}'],
            ['name' => 'ProShop NO',    'domain' => 'proshop.no',           'search_url' => 'https://www.proshop.no/Search?search={query}'],
            ['name' => 'Multicom',      'domain' => 'multicom.no',          'search_url' => 'https://www.multicom.no/search?q={query}'],
        ],
        'PL' => [ // Poland
            ['name' => 'Ceneo',         'domain' => 'ceneo.pl',             'search_url' => 'https://www.ceneo.pl/szukaj-{query}'],
            ['name' => 'X-Kom',         'domain' => 'x-kom.pl',             'search_url' => 'https://www.x-kom.pl/szukaj?q={query}'],
            ['name' => 'Morele',        'domain' => 'morele.net',           'search_url' => 'https://www.morele.net/wyszukiwarka/?q={query}'],
            ['name' => 'Komputronik',   'domain' => 'komputronik.pl',       'search_url' => 'https://www.komputronik.pl/search?q={query}'],
        ],
        'PT' => [ // Portugal
            ['name' => 'PCDIGA',        'domain' => 'pcdiga.com',           'search_url' => 'https://www.pcdiga.com/pesquisa?q={query}'],
            ['name' => 'PCComponentes PT','domain'=> 'pccomponentes.pt',    'search_url' => 'https://www.pccomponentes.pt/search?query={query}'],
            ['name' => 'GlobalData',    'domain' => 'globaldata.pt',        'search_url' => 'https://globaldata.pt/search?q={query}'],
        ],
        'RO' => [ // Romania
            ['name' => 'PC Garage',     'domain' => 'pcgarage.ro',          'search_url' => 'https://www.pcgarage.ro/cauta/{query}'],
            ['name' => 'Altex',         'domain' => 'altex.ro',             'search_url' => 'https://altex.ro/caut/?q={query}'],
            ['name' => 'eMAG RO',       'domain' => 'emag.ro',              'search_url' => 'https://www.emag.ro/search/{query}'],
        ],
        'RU' => [ // Russia
            ['name' => 'DNS Shop',      'domain' => 'dns-shop.ru',          'search_url' => 'https://www.dns-shop.ru/search/?q={query}'],
            ['name' => 'Citilink',      'domain' => 'citilink.ru',          'search_url' => 'https://www.citilink.ru/search/?text={query}'],
            ['name' => 'Regard',        'domain' => 'regard.ru',            'search_url' => 'https://www.regard.ru/catalog/?search={query}'],
        ],
        'RS' => [ // Serbia
            ['name' => 'Gigatron',      'domain' => 'gigatron.rs',          'search_url' => 'https://gigatron.rs/pretraga?q={query}'],
            ['name' => 'Monitor RS',    'domain' => 'monitor.rs',           'search_url' => 'https://www.monitor.rs/search?q={query}'],
            ['name' => 'Atom RS',       'domain' => 'atom.rs',              'search_url' => 'https://atom.rs/pretraga?q={query}'],
            ['name' => 'Ananas',        'domain' => 'ananas.rs',            'search_url' => 'https://ananas.rs/search?q={query}'],
        ],
        'SK' => [ // Slovakia
            ['name' => 'Heureka SK',    'domain' => 'heureka.sk',           'search_url' => 'https://www.heureka.sk/?h%5Bfraze%5D={query}'],
            ['name' => 'Alza SK',       'domain' => 'alza.sk',              'search_url' => 'https://www.alza.sk/search.htm?exps={query}'],
        ],
        'SI' => [ // Slovenia
            ['name' => 'Ceneje',        'domain' => 'ceneje.si',            'search_url' => 'https://www.ceneje.si/Iskanje/{query}'],
            ['name' => 'MimoVrste',     'domain' => 'mimovrste.com',        'search_url' => 'https://www.mimovrste.com/iskanje?q={query}'],
            ['name' => 'Big Bang',      'domain' => 'bigbang.si',           'search_url' => 'https://www.bigbang.si/search/{query}'],
        ],
        'ES' => [ // Spain
            ['name' => 'MediaMarkt ES', 'domain' => 'mediamarkt.es',        'search_url' => 'https://www.mediamarkt.es/es/search.html?query={query}'],
        ],
        'SE' => [ // Sweden
            ['name' => 'Prisjakt',      'domain' => 'prisjakt.nu',          'search_url' => 'https://www.prisjakt.nu/search?search={query}'],
            ['name' => 'Inet',          'domain' => 'inet.se',              'search_url' => 'https://www.inet.se/search?term={query}'],
            ['name' => 'ProShop SE',    'domain' => 'proshop.se',           'search_url' => 'https://www.proshop.se/Search?search={query}'],
            ['name' => 'Elgiganten SE', 'domain' => 'elgiganten.se',        'search_url' => 'https://www.elgiganten.se/search?search={query}'],
        ],
        'CH' => [ // Switzerland
            ['name' => 'Digitec',       'domain' => 'digitec.ch',           'search_url' => 'https://www.digitec.ch/en/search?q={query}'],
            ['name' => 'TopPreise',     'domain' => 'toppreise.ch',         'search_url' => 'https://www.toppreise.ch/search?q={query}'],
        ],
        'UA' => [ // Ukraine
            ['name' => 'Rozetka',       'domain' => 'rozetka.com.ua',       'search_url' => 'https://rozetka.com.ua/search/?text={query}'],
            ['name' => 'Hotline UA',    'domain' => 'hotline.ua',           'search_url' => 'https://hotline.ua/sr/?q={query}'],
        ],
        'GB' => [ // United Kingdom
            ['name' => 'Amazon UK',     'domain' => 'amazon.co.uk',         'search_url' => 'https://www.amazon.co.uk/s?k={query}'],
            ['name' => 'Scan',          'domain' => 'scan.co.uk',           'search_url' => 'https://www.scan.co.uk/search?q={query}'],
            ['name' => 'Overclockers',  'domain' => 'overclockers.co.uk',   'search_url' => 'https://www.overclockers.co.uk/search?sSearch={query}'],
            ['name' => 'Currys',        'domain' => 'currys.co.uk',         'search_url' => 'https://www.currys.co.uk/search/{query}'],
            ['name' => 'SkinFlint',     'domain' => 'skinflint.co.uk',      'search_url' => 'https://skinflint.co.uk/?fs={query}'],
        ],

        // ===================== OCEANIA =====================
        'AU' => [ // Australia
            ['name' => 'PCCaseGear',    'domain' => 'pccasegear.com',       'search_url' => 'https://www.pccasegear.com/search?query={query}'],
            ['name' => 'Scorptec',      'domain' => 'scorptec.com.au',      'search_url' => 'https://www.scorptec.com.au/search?q={query}'],
            ['name' => 'Umart',         'domain' => 'umart.com.au',         'search_url' => 'https://www.umart.com.au/search?q={query}'],
            ['name' => 'Amazon AU',     'domain' => 'amazon.com.au',        'search_url' => 'https://www.amazon.com.au/s?k={query}'],
        ],
        'NZ' => [ // New Zealand
            ['name' => 'PriceSpy NZ',   'domain' => 'pricespy.co.nz',       'search_url' => 'https://pricespy.co.nz/search?search={query}'],
            ['name' => 'TradeMe',       'domain' => 'trademe.co.nz',        'search_url' => 'https://www.trademe.co.nz/a/marketplace/search?search_string={query}'],
        ],

        // ===================== THE AMERICAS =====================
        'AR' => [ // Argentina
            ['name' => 'CompraGamer',   'domain' => 'compragamer.com',      'search_url' => 'https://compragamer.com/index.php?criterio={query}'],
            ['name' => 'Venex',         'domain' => 'venex.com.ar',         'search_url' => 'https://venex.com.ar/buscar?q={query}'],
            ['name' => 'FullH4rd',      'domain' => 'fullh4rd.com.ar',      'search_url' => 'https://www.fullh4rd.com.ar/buscar?q={query}'],
            ['name' => 'Gezatek',       'domain' => 'gezatek.com.ar',       'search_url' => 'https://www.gezatek.com.ar/tienda/buscar?q={query}'],
            ['name' => 'MercadoLibre AR','domain' => 'mercadolibre.com.ar', 'search_url' => 'https://listado.mercadolibre.com.ar/{query}'],
        ],
        'BO' => [ // Bolivia
            ['name' => 'MercadoLibre BO','domain' => 'mercadolibre.com.bo', 'search_url' => 'https://listado.mercadolibre.com.bo/{query}'],
        ],
        'BR' => [ // Brazil
            ['name' => 'Kabum',         'domain' => 'kabum.com.br',         'search_url' => 'https://www.kabum.com.br/busca/{query}'],
            ['name' => 'Pichau',        'domain' => 'pichau.com.br',        'search_url' => 'https://www.pichau.com.br/search?q={query}'],
            ['name' => 'Terabyte',      'domain' => 'terabyteshop.com.br',  'search_url' => 'https://www.terabyteshop.com.br/busca?str={query}'],
            ['name' => 'Amazon BR',     'domain' => 'amazon.com.br',        'search_url' => 'https://www.amazon.com.br/s?k={query}'],
        ],
        'CL' => [ // Chile
            ['name' => 'SoloTodo',      'domain' => 'solotodo.cl',          'search_url' => 'https://www.solotodo.cl/search?search={query}'],
            ['name' => 'PCFactory',     'domain' => 'pcfactory.cl',         'search_url' => 'https://www.pcfactory.cl/buscar?query={query}'],
            ['name' => 'SPDigital',     'domain' => 'spdigital.cl',         'search_url' => 'https://www.spdigital.cl/search?q={query}'],
            ['name' => 'MercadoLibre CL','domain' => 'mercadolibre.cl',    'search_url' => 'https://listado.mercadolibre.cl/{query}'],
        ],
        'CO' => [ // Colombia
            ['name' => 'MercadoLibre CO','domain' => 'mercadolibre.com.co', 'search_url' => 'https://listado.mercadolibre.com.co/{query}'],
        ],
        'CR' => [ // Costa Rica
            ['name' => 'ExtremeTech CR','domain' => 'extremetechcr.com',    'search_url' => 'https://extremetechcr.com/?s={query}'],
            ['name' => 'Intelec CR',    'domain' => 'intelec.co.cr',        'search_url' => 'https://www.intelec.co.cr/?s={query}'],
        ],
        'MX' => [ // Mexico
            ['name' => 'CyberPuerta',   'domain' => 'cyberpuerta.mx',       'search_url' => 'https://www.cyberpuerta.mx/index.php?cl=search&searchparam={query}'],
            ['name' => 'DDTech',        'domain' => 'ddtech.mx',            'search_url' => 'https://ddtech.mx/busqueda/{query}'],
            ['name' => 'Dicotech',      'domain' => 'dicotech.com.mx',      'search_url' => 'https://www.dicotech.com.mx/search?q={query}'],
            ['name' => 'MercadoLibre MX','domain' => 'mercadolibre.com.mx', 'search_url' => 'https://listado.mercadolibre.com.mx/{query}'],
        ],
        'NI' => [ // Nicaragua
            ['name' => 'ComTech NI',    'domain' => 'comtech.com.ni',       'search_url' => 'https://comtech.com.ni/?s={query}'],
        ],
        'PE' => [ // Peru
            ['name' => 'Impacto',       'domain' => 'impacto.com.pe',       'search_url' => 'https://www.impacto.com.pe/buscar?q={query}'],
            ['name' => 'MemoryKings',   'domain' => 'memorykings.com.pe',   'search_url' => 'https://www.memorykings.com.pe/buscar?q={query}'],
            ['name' => 'MercadoLibre PE','domain' => 'mercadolibre.com.pe', 'search_url' => 'https://listado.mercadolibre.com.pe/{query}'],
        ],
        'PR' => [ // Puerto Rico
            ['name' => 'BandiTech',     'domain' => 'banditech.com',        'search_url' => 'https://banditech.com/?s={query}'],
        ],
        'TT' => [ // Trinidad and Tobago
            ['name' => 'KMS Electronics','domain' => 'kmselectronicstt.com','search_url' => 'https://kmselectronicstt.com/?s={query}'],
            ['name' => 'SuperTech TT',  'domain' => 'supertechtt.com',      'search_url' => 'https://www.supertechtt.com/?s={query}'],
        ],
        'US' => [ // United States
            ['name' => 'Amazon',        'domain' => 'amazon.com',           'search_url' => 'https://www.amazon.com/s?k={query}'],
            ['name' => 'Newegg',        'domain' => 'newegg.com',           'search_url' => 'https://www.newegg.com/p/pl?d={query}'],
            ['name' => 'Best Buy',      'domain' => 'bestbuy.com',          'search_url' => 'https://www.bestbuy.com/site/searchpage.jsp?st={query}'],
            ['name' => 'B&H Photo',     'domain' => 'bhphotovideo.com',     'search_url' => 'https://www.bhphotovideo.com/c/search?q={query}'],
            ['name' => 'Micro Center',  'domain' => 'microcenter.com',      'search_url' => 'https://www.microcenter.com/search/search_results.aspx?Ntt={query}'],
        ],
        'UY' => [ // Uruguay
            ['name' => 'Tranza',        'domain' => 'tranza.com',           'search_url' => 'https://www.tranza.com/buscar?q={query}'],
            ['name' => 'Banifox',       'domain' => 'banifox.com',          'search_url' => 'https://www.banifox.com/buscar?q={query}'],
            ['name' => 'MercadoLibre UY','domain' => 'mercadolibre.com.uy', 'search_url' => 'https://listado.mercadolibre.com.uy/{query}'],
        ],
        'VE' => [ // Venezuela
            ['name' => 'MercadoLibre VE','domain' => 'mercadolibre.com.ve', 'search_url' => 'https://listado.mercadolibre.com.ve/{query}'],
        ],
        'CA' => [ // Canada
            ['name' => 'Amazon CA',       'domain' => 'amazon.ca',          'search_url' => 'https://www.amazon.ca/s?k={query}'],
            ['name' => 'Canada Computers','domain' => 'canadacomputers.com','search_url' => 'https://www.canadacomputers.com/search/results_details.php?keywords={query}'],
            ['name' => 'Newegg CA',       'domain' => 'newegg.ca',          'search_url' => 'https://www.newegg.ca/p/pl?d={query}'],
            ['name' => 'Memory Express',  'domain' => 'memoryexpress.com',  'search_url' => 'https://www.memoryexpress.com/Search/Products?Search={query}'],
        ],

        // ===================== MIDDLE EAST =====================
        'BH' => [ // Bahrain
            ['name' => 'Advanti',       'domain' => 'advanti.com',          'search_url' => 'https://advanti.com/?s={query}'],
            ['name' => 'Gear Up',       'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Microless BH',  'domain' => 'microless.com',        'search_url' => 'https://bahrain.microless.com/search/{query}'],
        ],
        'IR' => [ // Iran
            ['name' => 'Torob',         'domain' => 'torob.com',            'search_url' => 'https://torob.com/search/?query={query}'],
        ],
        'IQ' => [ // Iraq
            ['name' => 'Alityan',       'domain' => 'alityan.com',          'search_url' => 'https://alityan.com/?s={query}'],
            ['name' => 'Elryan',        'domain' => 'elryan.com',           'search_url' => 'https://www.elryan.com/?s={query}'],
        ],
        'IL' => [ // Israel
            ['name' => 'TMS',           'domain' => 'tms.co.il',            'search_url' => 'https://tms.co.il/search?q={query}'],
            ['name' => 'KSP',           'domain' => 'ksp.co.il',            'search_url' => 'https://ksp.co.il/?select=**&q={query}'],
            ['name' => 'Ivory',         'domain' => 'ivory.co.il',          'search_url' => 'http://www.ivory.co.il/search?q={query}'],
            ['name' => 'Bug',           'domain' => 'bug.co.il',            'search_url' => 'https://bug.co.il/search?query={query}'],
        ],
        'JO' => [ // Jordan
            ['name' => 'CityCenter JO', 'domain' => 'citycenter.jo',        'search_url' => 'https://citycenter.jo/search?q={query}'],
        ],
        'KW' => [ // Kuwait
            ['name' => 'Gear Up KW',    'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Microless KW',  'domain' => 'microless.com',        'search_url' => 'https://kuwait.microless.com/search/{query}'],
        ],
        'LB' => [ // Lebanon
            ['name' => 'Ayoub Computers','domain' => 'ayoubcomputers.com',  'search_url' => 'https://ayoubcomputers.com/?s={query}'],
        ],
        'OM' => [ // Oman
            ['name' => 'Gear Up OM',    'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Microless OM',  'domain' => 'microless.com',        'search_url' => 'https://oman.microless.com/search/{query}'],
        ],
        'PS' => [ // Palestine
            ['name' => 'WataniMall',    'domain' => 'watanimall.com',       'search_url' => 'https://watanimall.com/?s={query}'],
        ],
        'QA' => [ // Qatar
            ['name' => 'PCBuilder Qatar','domain' => 'pcbuilderqatar.com',  'search_url' => 'https://pcbuilderqatar.com/?s={query}'],
            ['name' => 'Store974',      'domain' => 'store974.com',         'search_url' => 'https://store974.com/search?q={query}'],
            ['name' => 'Gear Up QA',    'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Microless QA',  'domain' => 'microless.com',        'search_url' => 'https://qatar.microless.com/search/{query}'],
        ],
        'SA' => [ // Saudi Arabia
            ['name' => 'Jarir',         'domain' => 'jarir.com',            'search_url' => 'https://www.jarir.com/sa-en/catalogsearch/result/?q={query}'],
            ['name' => 'Gear Up SA',    'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Amazon SA',     'domain' => 'amazon.sa',            'search_url' => 'https://www.amazon.sa/s?k={query}'],
            ['name' => 'Microless SA',  'domain' => 'microless.com',        'search_url' => 'https://saudi.microless.com/search/{query}'],
        ],
        'TR' => [ // Turkey
            ['name' => 'Akakce',        'domain' => 'akakce.com',           'search_url' => 'https://www.akakce.com/arama/?q={query}'],
            ['name' => 'Hepsiburada',   'domain' => 'hepsiburada.com',      'search_url' => 'https://www.hepsiburada.com/ara?q={query}'],
            ['name' => 'Vatan',         'domain' => 'vatanbilgisayar.com',  'search_url' => 'https://www.vatanbilgisayar.com/arama/{query}/'],
            ['name' => 'Itopya',        'domain' => 'itopya.com',           'search_url' => 'https://www.itopya.com/arama/?q={query}'],
            ['name' => 'Trendyol',      'domain' => 'trendyol.com',         'search_url' => 'https://www.trendyol.com/sr?q={query}'],
            ['name' => 'Amazon TR',     'domain' => 'amazon.com.tr',        'search_url' => 'https://www.amazon.com.tr/s?k={query}'],
        ],
        'AE' => [ // UAE
            ['name' => 'Dubai Gamers',  'domain' => 'dubaigamers.com',      'search_url' => 'https://dubaigamers.com/?s={query}'],
            ['name' => 'GCC Gamers',    'domain' => 'gccgamers.com',        'search_url' => 'https://gccgamers.com/?s={query}'],
            ['name' => 'Geekay',        'domain' => 'geekay.com',           'search_url' => 'https://www.geekay.com/search?q={query}'],
            ['name' => 'Gear Up AE',    'domain' => 'gear-up.me',           'search_url' => 'https://gear-up.me/catalogsearch/result/?q={query}'],
            ['name' => 'Amazon AE',     'domain' => 'amazon.ae',            'search_url' => 'https://www.amazon.ae/s?k={query}'],
            ['name' => 'Microless AE',  'domain' => 'microless.com',        'search_url' => 'https://uae.microless.com/search/{query}'],
        ],
    ];
    // Default: use Google Shopping as fallback
    $fallback = [
        ['name' => 'Google Shopping', 'domain' => 'shopping.google.com', 'search_url' => 'https://www.google.com/search?tbm=shop&q={query}'],
        ['name' => 'Amazon',          'domain' => 'amazon.com',          'search_url' => 'https://www.amazon.com/s?k={query}'],
        ['name' => 'AliExpress',      'domain' => 'aliexpress.com',      'search_url' => 'https://www.aliexpress.com/wholesale?SearchText={query}'],
    ];
    return $stores[$countryCode] ?? $fallback;
}

/**
 * Generate a clickable search URL for a specific component at a store.
 * If storeName is provided, tries to match that store first.
 */
function generateStoreSearchUrl(string $brand, string $model, string $countryCode, string $storeName = ''): string {
    $stores = getRegionalStores($countryCode);
    if (empty($stores)) {
        $query = urlencode("$brand $model buy");
        return "https://www.google.com/search?tbm=shop&q=$query";
    }
    // Try to match the AI-specified store name
    $store = $stores[0]; // default to first
    if ($storeName) {
        foreach ($stores as $s) {
            if (stripos($s['name'], $storeName) !== false || stripos($storeName, $s['name']) !== false) {
                $store = $s;
                break;
            }
        }
    }
    $query = urlencode("$brand $model");
    return str_replace('{query}', $query, $store['search_url']);
}

/**
 * Load the image URL cache from the JSON file.
 * Returns an associative array of component name → image URL.
 */
function loadImageCache(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $cacheFile = __DIR__ . '/../scripts/image_cache/image_urls_cache.json';
    if (file_exists($cacheFile)) {
        $json = file_get_contents($cacheFile);
        $data = json_decode($json, true);
        if (is_array($data)) {
            // Filter out empty values
            $cache = array_filter($data, function($url) {
                return !empty($url) && is_string($url);
            });
            return $cache;
        }
    }
    $cache = [];
    return $cache;
}

function isValidHttpUrl(?string $url): bool {
    if (empty($url) || !is_string($url)) {
        return false;
    }
    return (bool)preg_match('#^https?://#i', trim($url));
}

function normalizeComponentTypeFromFilename(string $filePath): string {
    $base = strtolower(pathinfo($filePath, PATHINFO_FILENAME));
    $map = [
        'cpu' => 'cpu',
        'gpu' => 'gpu',
        'motherboard' => 'motherboard',
        'ram' => 'ram',
        'storage' => 'storage',
        'psu' => 'psu',
        'case' => 'case',
        'cooler' => 'cooler',
        'monitor' => 'monitor',
    ];
    return $map[$base] ?? $base;
}

function loadComponentCatalog(): array {
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [];
    $files = glob(COMPONENTS_DATA_DIR . '/*.json') ?: [];
    foreach ($files as $file) {
        $type = normalizeComponentTypeFromFilename($file);
        $json = @file_get_contents($file);
        $rows = json_decode((string)$json, true);
        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $price = (float)($row['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $row['type'] = $type;
            $row['image_url'] = isset($row['image_url']) && is_string($row['image_url']) ? trim($row['image_url']) : '';
            $row['link'] = isset($row['link']) && is_string($row['link']) ? trim($row['link']) : '';
            $catalog[$type][] = $row;
        }
    }

    foreach ($catalog as $type => &$items) {
        usort($items, fn($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));
    }

    return $catalog;
}

function mapCategoryToComponentType(string $requestedType): string {
    $t = strtolower(trim($requestedType));
    $aliases = [
        'processor' => 'cpu',
        'graphics card' => 'gpu',
        'video card' => 'gpu',
        'memory' => 'ram',
        'power supply' => 'psu',
        'case fan' => 'case-fan',
        'fan' => 'case-fan',
    ];
    return $aliases[$t] ?? $t;
}

function componentReasonFromData(array $component, string $useCase): string {
    $type = strtolower($component['type'] ?? 'component');
    $name = trim(($component['brand'] ?? '') . ' ' . ($component['model'] ?? ''));
    if ($type === 'cpu' && isset($component['cores'])) {
        return $name . ' with ' . $component['cores'] . ' is a strong fit for ' . $useCase . ' workloads.';
    }
    if ($type === 'gpu' && isset($component['vram'])) {
        return $name . ' offers ' . $component['vram'] . ' for smooth ' . $useCase . ' performance.';
    }
    if ($type === 'ram' && isset($component['capacity'])) {
        return $component['capacity'] . ' memory helps keep multitasking responsive.';
    }
    if ($type === 'storage' && isset($component['type'])) {
        return $name . ' gives fast load times and solid daily responsiveness.';
    }
    return $name . ' is selected based on your budget and component balance.';
}

function isSocketCompatible(?array $cpu, ?array $motherboard): bool {
    if (!$cpu || !$motherboard) {
        return true;
    }
    $cpuSocket = strtolower((string)($cpu['socket'] ?? ''));
    $moboSocket = strtolower((string)($motherboard['socket'] ?? ''));
    if ($cpuSocket === '' || $moboSocket === '') {
        return true;
    }
    return $cpuSocket === $moboSocket;
}

function isRamCompatibleWithMotherboard(?array $ram, ?array $motherboard): bool {
    if (!$ram || !$motherboard) {
        return true;
    }
    $ramType = strtolower((string)($ram['type'] ?? ''));
    $moboType = strtolower((string)($motherboard['memory_type'] ?? ''));
    if ($ramType === '' || $moboType === '') {
        return true;
    }
    return strpos($ramType, $moboType) !== false || strpos($moboType, $ramType) !== false;
}

function caseFitsMotherboard(?array $pcCase, ?array $motherboard): bool {
    if (!$pcCase || !$motherboard) {
        return true;
    }
    $caseForm = strtolower((string)($pcCase['form_factor'] ?? ''));
    $moboForm = strtolower((string)($motherboard['form_factor'] ?? ''));
    if ($caseForm === '' || $moboForm === '') {
        return true;
    }
    if ($caseForm === $moboForm) {
        return true;
    }
    if (strpos($caseForm, 'atx') !== false && strpos($moboForm, 'micro') !== false) {
        return true;
    }
    if (strpos($caseForm, 'atx') !== false && strpos($moboForm, 'mini') !== false) {
        return true;
    }
    if (strpos($caseForm, 'micro') !== false && strpos($moboForm, 'mini') !== false) {
        return true;
    }
    return false;
}

function selectComponentByTarget(array $candidates, ?float $targetPrice, array $selected = []): ?array {
    if (empty($candidates)) {
        return null;
    }

    $filtered = [];
    foreach ($candidates as $candidate) {
        $type = strtolower((string)($candidate['type'] ?? ''));
        if ($type === 'motherboard' && isset($selected['cpu']) && !isSocketCompatible($selected['cpu'], $candidate)) {
            continue;
        }
        if ($type === 'ram' && isset($selected['motherboard']) && !isRamCompatibleWithMotherboard($candidate, $selected['motherboard'])) {
            continue;
        }
        if ($type === 'case' && isset($selected['motherboard']) && !caseFitsMotherboard($candidate, $selected['motherboard'])) {
            continue;
        }
        if ($type === 'cooler' && isset($selected['cpu']) && !empty($candidate['socket_support']) && is_array($candidate['socket_support'])) {
            $cpuSocket = strtolower((string)($selected['cpu']['socket'] ?? ''));
            $supports = array_map(fn($s) => strtolower((string)$s), $candidate['socket_support']);
            if ($cpuSocket !== '' && !in_array($cpuSocket, $supports, true)) {
                continue;
            }
        }
        $filtered[] = $candidate;
    }

    if (empty($filtered)) {
        $filtered = $candidates;
    }

    if ($targetPrice === null || $targetPrice <= 0) {
        $middle = (int)floor((count($filtered) - 1) / 2);
        return $filtered[$middle] ?? $filtered[0];
    }

    $best = null;
    $bestDelta = PHP_FLOAT_MAX;
    foreach ($filtered as $candidate) {
        $price = (float)($candidate['price'] ?? 0);
        if ($price <= 0) {
            continue;
        }
        $delta = abs($targetPrice - $price);
        if ($delta < $bestDelta) {
            $best = $candidate;
            $bestDelta = $delta;
        }
    }

    return $best ?? $filtered[0] ?? null;
}

function isPriceRefreshRequest(string $userMessage): bool {
    return (bool)preg_match('/(update|refresh|latest|current|recheck)\s+(?:all\s+)?(?:component\s+)?prices?/i', $userMessage)
        || (bool)preg_match('/prices?\s+(update|refresh|recheck)/i', $userMessage);
}

function extractPriceFromHtml(string $html): ?float {
    $patterns = [
        '/"price"\s*:\s*"?([0-9][0-9,\.]{1,20})"?/i',
        '/property=["\']product:price:amount["\'][^>]*content=["\']([0-9][0-9,\.]{1,20})["\']/i',
        '/itemprop=["\']price["\'][^>]*content=["\']([0-9][0-9,\.]{1,20})["\']/i',
        '/(?:₱|PHP|USD|\$|EUR|€)\s*([0-9][0-9,\.]{1,20})/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $raw = str_replace(',', '', (string)$m[1]);
            $value = (float)$raw;
            if ($value > 0 && $value < 5000000) {
                return round($value, 2);
            }
        }
    }

    return null;
}

function fetchCurrentPriceFromProductLink(string $url): ?float {
    if (!isValidHttpUrl($url)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $html = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 400 || !is_string($html) || $html === '') {
        return null;
    }

    return extractPriceFromHtml($html);
}

function refreshComponentPricesFromJson(): array {
    $summary = [
        'files_scanned' => 0,
        'components_checked' => 0,
        'prices_updated' => 0,
        'files_updated' => 0,
    ];

    $files = glob(COMPONENTS_DATA_DIR . '/*.json') ?: [];
    foreach ($files as $file) {
        $summary['files_scanned']++;
        $raw = @file_get_contents($file);
        $rows = json_decode((string)$raw, true);
        if (!is_array($rows)) {
            continue;
        }

        $fileChanged = false;
        foreach ($rows as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $summary['components_checked']++;
            $link = trim((string)($item['link'] ?? ''));
            if (!isValidHttpUrl($link)) {
                continue;
            }

            $detectedPrice = fetchCurrentPriceFromProductLink($link);
            if ($detectedPrice === null) {
                continue;
            }

            $currentPrice = (float)($item['price'] ?? 0);
            if (abs($currentPrice - $detectedPrice) >= 0.01) {
                $item['price'] = $detectedPrice;
                $summary['prices_updated']++;
                $fileChanged = true;
            }
        }
        unset($item);

        $prices = [];
        foreach ($rows as $item) {
            $p = (float)($item['price'] ?? 0);
            if ($p > 0) {
                $prices[] = $p;
            }
        }

        if (!empty($prices)) {
            $min = min($prices);
            $max = max($prices);
            foreach ($rows as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['pricing_range']) || !is_array($item['pricing_range'])
                    || (float)($item['pricing_range']['min'] ?? 0) !== (float)$min
                    || (float)($item['pricing_range']['max'] ?? 0) !== (float)$max) {
                    $item['pricing_range'] = ['min' => $min, 'max' => $max];
                    $fileChanged = true;
                }
            }
            unset($item);
        }

        if ($fileChanged) {
            @file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $summary['files_updated']++;
        }
    }

    return $summary;
}

/**
 * Look up a component's image URL from the local cache.
 * Uses fuzzy matching: tries exact match, then partial model match.
 */
function lookupCachedImageUrl(string $brand, string $model, string $type = ''): ?string {
    $cache = loadImageCache();
    if (empty($cache)) return null;
    
    $fullName = trim("$brand $model");
    
    // 1. Exact match
    if (!empty($cache[$fullName])) {
        return $cache[$fullName];
    }
    
    // 2. Try model name only
    if (!empty($cache[$model])) {
        return $cache[$model];
    }
    
    // 3. Case-insensitive search
    $fullNameLower = strtolower($fullName);
    $modelLower = strtolower($model);
    foreach ($cache as $name => $url) {
        $nameLower = strtolower($name);
        // Full name match (case insensitive)
        if ($nameLower === $fullNameLower) {
            return $url;
        }
        // Model contained in cache key
        if ($modelLower && strlen($modelLower) > 5 && strpos($nameLower, $modelLower) !== false) {
            return $url;
        }
        // Cache key contained in full name
        if (strlen($nameLower) > 5 && strpos($fullNameLower, $nameLower) !== false) {
            return $url;
        }
    }
    
    return null;
}

/**
 * Generate a product image URL for a component.
 * Uses only verified real URLs and never generates placeholders.
 */
function generateComponentImageUrl(string $brand, string $model, string $type): string {
    $cached = lookupCachedImageUrl($brand, $model, $type);
    if ($cached) {
        return $cached;
    }
    return '';
}

/**
 * Validate an AI-provided image URL.
 * Returns the URL if it looks like a real direct image link, null otherwise.
 * Filters out: search pages, hallucinated paths, and non-image URLs.
 */
function validateImageUrl(?string $url): ?string {
    if (empty($url) || !is_string($url)) return null;
    $url = trim($url);
    
    // Must start with http(s)
    if (!preg_match('#^https?://#i', $url)) return null;
    
    // Reject Google search URLs (not actual images)
    if (preg_match('#google\.com/(search|imgres|images)#i', $url)) return null;
    
    // Reject known non-image patterns
    if (preg_match('#(search\?|/search/|/catalogsearch/|/szukaj|/arama|/hledani|/paieska)#i', $url)) return null;
    
    // Reject data: URIs that snuck through
    if (strpos($url, 'data:image') === 0) return null;
    
    // Accept known good CDN patterns
    $trustedPatterns = [
        '#cdna?\.pcpartpicker\.com/static/#i',
        '#encrypted-tbn\d+\.gstatic\.com/images#i',
        '#upload\.wikimedia\.org#i',
        '#m\.media-amazon\.com/images/#i',
        '#images-na\.ssl-images-amazon\.com#i',
        '#c1\.newegg\.com/#i',
        '#pisces\.bbystatic\.com/image/#i',
        '#i\.imgur\.com/#i',
    ];
    foreach ($trustedPatterns as $pattern) {
        if (preg_match($pattern, $url)) return $url;
    }
    
    // Accept URLs ending with common image extensions
    if (preg_match('#\.(jpg|jpeg|png|webp|gif|svg|avif)(\?.*)?$#i', $url)) {
        return $url;
    }
    
    // Accept URLs with image-related path segments (CDN patterns)
    if (preg_match('#/(image|img|photo|product|media|assets|cdn|static|upload)s?/#i', $url)) {
        return $url;
    }
    
    // If none of the above matched, it's probably not a direct image URL
    return null;
}

/**
 * Get the best image URL for a component.
 * Priority: 1) Validated URL, 2) Cache lookup, 3) empty string.
 */
function resolveComponentImageUrl(?string $aiImageUrl, string $brand, string $model, string $type): string {
    // Try AI-provided URL first (if valid)
    $validated = validateImageUrl($aiImageUrl);
    if ($validated) return $validated;
    
    // Try cache lookup
    $cached = lookupCachedImageUrl($brand, $model, $type);
    if ($cached) return $cached;
    
    // No synthetic placeholders allowed; return empty and let UI show explicit missing-image state.
    return '';
}

/**
 * Ask OpenRouter to analyze a user message in the context of PC building.
 * Returns a structured JSON object with intent, budget, use case, etc.
 */
function analyzeUserMessage(string $userMessage, array $conversationHistory = []): array {
    $systemPrompt = <<<'PROMPT'
You are a PC build analysis engine. Your job is to accurately interpret the user's PC-related request.

Analyze the user message and return ONLY valid JSON (no markdown, no code fences) with these fields:
{
  "intent": "build_recommendation" | "component_search" | "upgrade_suggestion" | "tips_and_hacks" | "where_to_buy" | "general_question" | "follow_up" | "greeting" | "off_topic",
  "budget": null or number (in PHP pesos),
  "use_case": "gaming" | "professional" | "productivity" | "streaming" | "general",
  "specific_components": ["cpu","gpu",...] or [],
  "performance_needs": ["gaming","professional",...] or [],
  "is_follow_up": true/false,
  "follow_up_context": "brief description of what the follow-up is about" or null,
  "brand_preference": null or "brand name",
  "key_requirements": ["short requirement descriptions"],
  "needs_full_build": true/false,
  "color_theme": null or "color preference (e.g. white, black, rgb)",
  "form_factor_preference": null or "ATX" | "mATX" | "ITX" | "SFF",
  "special_requirements": ["wifi", "bluetooth", "rgb", "quiet", "compact", "no-gpu", "dual-monitor", "4k-gaming", "1080p-gaming", "1440p-gaming"] or []
}

INPUT UNDERSTANDING RULES:
1. Extract the BUDGET accurately:
   - "50k" = 50000, "₱30,000" = 30000, "30K PHP" = 30000
   - "$1000" or "1000 USD" = convert mentally but store raw number
   - If user says "around" or "about", treat as flexible ±10%
   - If no budget mentioned, set null

2. Detect the USE CASE from context:
   - Gaming keywords: "gaming", "play games", "fps", "Valorant", "Genshin", "streaming games"
   - Professional: "video editing", "3D rendering", "CAD", "architecture", "Blender", "After Effects", "Premiere Pro"
   - Productivity: "office", "work", "coding", "programming", "school", "multitasking"
   - Streaming: "streaming", "OBS", "content creation", "YouTube", "Twitch"
   - General: if none of the above, default to "general"

3. Detect PREFERENCES:
   - Brand: "AMD build", "Intel only", "Nvidia preferred", "team red"
   - Color: "white build", "all black", "RGB", "no RGB"
   - Size: "small form factor", "compact", "mini ITX", "mATX"
   - Special: "need WiFi", "no GPU needed", "quiet PC", "dual monitor setup"

INTENT CLASSIFICATION RULES:
- "off_topic": Messages NOT related to PCs, laptops, computer hardware, peripherals, gaming setups, tech devices, or technology. Examples: cooking recipes, weather, sports, politics, relationships, math homework.
- "tips_and_hacks": PC tips, troubleshooting, optimization, maintenance, overclocking, cable management, cooling tips, how-to guides, performance hacks, driver updates, BIOS settings, OS optimization.
- "general_question": General PC/tech questions (e.g., "what's the difference between DDR4 and DDR5?", "is 16GB RAM enough?").
- "greeting": Simple hello/hi messages.
- "build_recommendation": User wants a full PC build or component recommendations with a budget. Also when user describes a use case implying they want a build.
- "component_search": User is looking for specific component types (e.g., "best GPU under 20k").
- "upgrade_suggestion": User wants to upgrade existing components.
- "where_to_buy": User asks where to purchase components.
- "follow_up": Message references a previous recommendation or continues a prior topic.

Analyze conversation history for context. A follow-up like "make it cheaper" or "swap the GPU" references the previous build.
PROMPT;
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    // Add last 6 messages of history for context
    $historySlice = array_slice($conversationHistory, -6);
    foreach ($historySlice as $msg) {
        $role = $msg['role'] === 'assistant' ? 'assistant' : 'user';
        $content = $msg['content'] ?? '';
        // Truncate long messages
        if (strlen($content) > 500) {
            $content = substr($content, 0, 500) . '...';
        }
        $messages[] = ['role' => $role, 'content' => $content];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];
    $raw = callOpenRouter($messages, 0.2, 512);
    $parsed = fixAIJson($raw);
    if (!is_array($parsed)) {
        aiLog("Failed to parse analysis JSON: $raw", 'WARN');
        // Fallback to regex-based parsing
        return fallbackParseQuery($userMessage);
    }
    return $parsed;
}

/**
 * Generate the final user-friendly response using OpenRouter.
 */
function generateAIResponseText(
    string $userMessage,
    array $analysis,
    array $components,
    array $conversationHistory,
    array $budgetAnalysis = [],
    array $upgradeData = [],
    array $location = []
): string {
    $country  = $location['country'] ?? 'Philippines';
    $symbol   = $location['currency_symbol'] ?? '₱';
    $currency = $location['currency'] ?? 'PHP';
    $stores   = getRegionalStores($location['country_code'] ?? 'PH');
    $storeNames = implode(', ', array_map(fn($s) => $s['name'], array_slice($stores, 0, 5)));
    $systemPrompt = <<<PROMPT
You are SmartSpecs, a friendly and approachable PC building assistant.
You help people build or upgrade their PCs with the best components for their needs and budget.

CRITICAL RESPONSE RULES:
1. Write in a casual, conversational tone — like talking to a friend. NO technical jargon unless explaining something.
2. DO NOT list component names, specs, or prices in your introduction text for build recommendations. The components are shown separately in a table below your message.
3. Instead, give a brief, encouraging overview: explain the build's strengths in simple terms.
4. Keep your response SHORT — 2-4 sentences maximum for build recommendations.
5. If the budget is tight, be honest but positive about what's achievable. Mention the compromises made.
6. The user is located in {$country}. Reference local stores when relevant: {$storeNames}.
7. Prices are in {$currency} ({$symbol}).
8. Do NOT use HTML tags. Use Markdown formatting instead.
9. Do NOT repeat or summarize the component list — it's already displayed in a table.
10. Be warm, encouraging, and helpful. Use simple language anyone can understand.

BUDGET-SPECIFIC BEHAVIOR:
- **Tight budget (below minimum)**: Be honest that the budget is challenging. Mention what compromises were made (e.g., "We went with an APU build to save on GPU costs" or "Previous-gen parts give great value here"). Stay positive about what IS achievable.
- **Comfortable budget**: Be enthusiastic about the solid build the budget allows.
- **High budget**: Express excitement about the premium components and what they enable.
- **No budget specified**: Ask what budget range they're working with, or provide a balanced mid-range suggestion.

USE CASE CONTEXT (tailor your response):
- **Gaming**: Mention what games/settings the build can handle (e.g., "This will crush Valorant at 1080p and handle Genshin Impact beautifully")
- **Professional**: Reference the specific workflow benefits (e.g., "Rendering in Premiere Pro will be significantly faster")
- **Productivity**: Focus on multitasking, reliability, speed for daily tasks
- **Streaming**: Mention dual-purpose capability (gaming + streaming simultaneously)

FORMATTING RULES (use Markdown):
- Use **bold text** for emphasis or important terms
- Use bullet points (- ) or numbered lists (1. ) for tips, steps, or multiple points
- Use line breaks between paragraphs for readability
- For tips/advice/how-to responses, organize content with clear sections using **bold headers**
- For greeting responses, keep it very short (1-2 sentences)

FOR TIPS/HACKS/ADVICE QUESTIONS:
- Provide detailed, actionable advice organized in clear bullet points or numbered steps
- Use **bold** for key terms and section headers
- Include specific, practical tips the user can actually follow
- You can be more detailed (5-10 points) since there's no component table below
- Reference local stores ({$storeNames}) for purchases if relevant

FOR GENERAL QUESTIONS:
- Answer thoroughly with clear formatting
- Use bullet points for comparisons or lists
- Be educational but not overly technical

EXAMPLES OF GOOD RESPONSES FOR BUILDS:
- "Great news! Your ₱50k budget gives us plenty of room for a solid gaming setup. This build will run Valorant and Genshin Impact smoothly at high settings. All parts are compatible and I've kept it within your budget — check out the parts below!"
- "With ₱25k, we're working with a tighter budget, so I went with an APU build that skips the dedicated GPU for now. It'll handle casual gaming and productivity tasks well, and you can always add a GPU later!"

EXAMPLES OF GOOD RESPONSES FOR TIPS:
- "**Cable Management Tips:**\n\n1. **Route cables behind the motherboard tray** — most modern cases have cutouts for this\n2. **Use velcro ties instead of zip ties** — easier to adjust later\n3. **Start with the 24-pin ATX cable** — it's the biggest and hardest to route"

EXAMPLES OF BAD RESPONSES (DO NOT DO THIS):
- "I recommend an AMD Ryzen 5 7600 paired with an RTX 4060..." (Don't list components in build intros!)
- Plain walls of text with no formatting
PROMPT;
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    // Add conversation history (last 8 messages)
    $historySlice = array_slice($conversationHistory, -8);
    foreach ($historySlice as $msg) {
        $role = $msg['role'] === 'assistant' ? 'assistant' : 'user';
        $content = $msg['content'] ?? '';
        if (strlen($content) > 800) {
            $content = substr($content, 0, 800) . '...';
        }
        $messages[] = ['role' => $role, 'content' => $content];
    }
    // Build context for the AI (not shown to user, just helps the AI write a better response)
    $contextParts = [];
    if (!empty($analysis)) {
        $contextParts[] = "## Request context (internal, don't repeat to user):";
        $contextParts[] = "- Intent: " . ($analysis['intent'] ?? 'unknown');
        $contextParts[] = "- Budget: " . ($analysis['budget'] ? "{$symbol}" . number_format($analysis['budget']) : 'Not specified');
        $contextParts[] = "- Use case: " . ($analysis['use_case'] ?? 'general');
        if (!empty($analysis['color_theme'])) {
            $contextParts[] = "- Color preference: " . $analysis['color_theme'];
        }
        if (!empty($analysis['special_requirements'])) {
            $contextParts[] = "- Special requirements: " . implode(', ', $analysis['special_requirements']);
        }
    }
    if (!empty($budgetAnalysis)) {
        $contextParts[] = "\n## Budget note:";
        $contextParts[] = "- Budget: {$symbol}" . number_format($budgetAnalysis['user_budget'] ?? 0);
        $contextParts[] = "- Feasible: " . ($budgetAnalysis['is_feasible'] ? 'Yes' : 'No');
        if (!empty($budgetAnalysis['message'])) {
            $contextParts[] = "- Note: " . $budgetAnalysis['message'];
        }
    }
    if (!empty($components)) {
        // Filter out _build_meta from component count
        $compCount = count(array_filter($components, fn($c, $k) => is_int($k), ARRAY_FILTER_USE_BOTH));
        $contextParts[] = "\n## Components already selected (shown in table, DON'T list them):";
        $contextParts[] = "- " . $compCount . " components have been selected and will be displayed in a table.";
        $totalCost = array_sum(array_map(fn($c) => is_array($c) && isset($c['price']) ? (float)$c['price'] : 0, $components));
        $contextParts[] = "- Total build cost: {$symbol}" . number_format($totalCost, 2);
        // Include build metadata if available
        $buildMeta = $components['_build_meta'] ?? null;
        if ($buildMeta) {
            if (!empty($buildMeta['build_name'])) {
                $contextParts[] = "- Build name: " . $buildMeta['build_name'];
            }
            if (!empty($buildMeta['assumptions'])) {
                $contextParts[] = "- Assumptions made: " . implode('; ', $buildMeta['assumptions']);
            }
            if (!empty($buildMeta['compatibility_notes'])) {
                $contextParts[] = "- Compatibility verified: " . implode('; ', $buildMeta['compatibility_notes']);
            }
        }
    }
    if (!empty($upgradeData)) {
        $contextParts[] = "\n## Upgrade context (shown in table, DON'T list specifics):";
        $contextParts[] = "- " . count($upgradeData) . " component types have upgrade suggestions ready.";
    }
    if (!empty($contextParts)) {
        $messages[] = [
            'role' => 'system',
            'content' => implode("\n", $contextParts)
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];
    return callOpenRouter($messages, 0.7, 1024);
}

// ---------------------------------------------------------------------------
// Query Parsing (Regex-based fallback)
// ---------------------------------------------------------------------------

function fallbackParseQuery(string $query): array {
    $lower = strtolower($query);
    $result = [
        'intent'             => 'general_question',
        'budget'             => null,
        'use_case'           => 'general',
        'specific_components'=> [],
        'performance_needs'  => [],
        'is_follow_up'       => false,
        'follow_up_context'  => null,
        'brand_preference'   => null,
        'key_requirements'   => [],
        'needs_full_build'   => false,
    ];
    // Detect budget
    foreach (PRICE_PATTERNS as $pattern) {
        if (preg_match($pattern, $lower, $m)) {
            $val = (float) str_replace(',', '', $m[1]);
            // Handle "k" suffix
            if (strpos($pattern, '\d+)\s*k') !== false || preg_match('/(\d+)\s*k/i', $lower, $km)) {
                if (isset($km)) {
                    $val = (float)$km[1] * 1000;
                } elseif ($val < 1000) {
                    $val *= 1000;
                }
            }
            if ($val > 0) {
                $result['budget'] = $val;
                break;
            }
        }
    }
    // Detect component types
    foreach (COMPONENT_TYPE_KEYWORDS as $type => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($lower, $kw) !== false) {
                $result['specific_components'][] = $type;
                break;
            }
        }
    }
    // Detect performance needs / use case
    foreach (PERFORMANCE_KEYWORDS as $need => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($lower, $kw) !== false) {
                $result['performance_needs'][] = $need;
                $result['use_case'] = $need;
                break;
            }
        }
    }
    // Detect build intent
    $buildPhrases = ['pc build','computer build','desktop build','build a pc','build me','magkano','recommend','suggest','setup','full build','complete build'];
    foreach ($buildPhrases as $bp) {
        if (strpos($lower, $bp) !== false) {
            $result['intent'] = 'build_recommendation';
            $result['needs_full_build'] = true;
            break;
        }
    }
    // Detect upgrade intent
    $upgradeWords = ['upgrade','upgrading','improve','better','future-proof','future proof','next level'];
    foreach ($upgradeWords as $uw) {
        if (strpos($lower, $uw) !== false) {
            $result['intent'] = 'upgrade_suggestion';
            break;
        }
    }
    // Detect tips/hacks
    $tipWords = ['tip','tips','trick','tricks','hack','hacks','advice','how to','guide'];
    foreach ($tipWords as $tw) {
        if (strpos($lower, $tw) !== false) {
            $result['intent'] = 'tips_and_hacks';
            break;
        }
    }
    // Where to buy
    $buyWords = ['where to buy','where can i buy','buy','shop','store','purchase','online shop'];
    foreach ($buyWords as $bw) {
        if (strpos($lower, $bw) !== false) {
            $result['intent'] = 'where_to_buy';
            break;
        }
    }
    // Brand detection
    $brands = ['intel','amd','nvidia','asus','msi','gigabyte','corsair','samsung','western digital','seagate','kingston','crucial','nzxt','cooler master','thermaltake','evga','zotac','sapphire','asrock','biostar'];
    foreach ($brands as $brand) {
        if (strpos($lower, $brand) !== false) {
            $result['brand_preference'] = ucfirst($brand);
            break;
        }
    }
    // If budget + general PC words but no specific component → full build
    if ($result['budget'] && !$result['needs_full_build']) {
        $generalPcWords = ['pc','computer','setup','build','desktop','rig'];
        foreach ($generalPcWords as $gpw) {
            if (strpos($lower, $gpw) !== false) {
                $result['needs_full_build'] = true;
                if ($result['intent'] === 'general_question') {
                    $result['intent'] = 'build_recommendation';
                }
                break;
            }
        }
    }
    return $result;
}

// ---------------------------------------------------------------------------
// Online Component Search (AI-Powered)
// ---------------------------------------------------------------------------

function getRequiredBuildTypes(?float $budget): array {
    $types = ['cpu', 'motherboard', 'ram', 'storage', 'psu', 'case', 'cooler'];
    if ($budget === null || $budget >= 18000) {
        $types[] = 'gpu';
    }
    return $types;
}

function getTargetPriceForType(string $type, ?float $budget, string $useCase): ?float {
    if ($budget === null || $budget <= 0) {
        return null;
    }
    $alloc = BUDGET_ALLOCATIONS[$useCase] ?? BUDGET_ALLOCATIONS['general'];
    $weight = $alloc[$type] ?? 0.06;
    return max(1, $budget * $weight);
}

function filterCandidatesByBrand(array $candidates, ?string $brandPreference): array {
    if (empty($brandPreference)) {
        return $candidates;
    }
    $preferred = array_values(array_filter($candidates, function($item) use ($brandPreference) {
        $brand = (string)($item['brand'] ?? '');
        return stripos($brand, $brandPreference) !== false;
    }));
    return !empty($preferred) ? $preferred : $candidates;
}

function toRecommendationComponent(array $item, string $type, string $currency, string $useCase): array {
    $brand = (string)($item['brand'] ?? 'Unknown');
    $model = (string)($item['model'] ?? ($item['name'] ?? 'Unknown'));
    $image = resolveComponentImageUrl((string)($item['image_url'] ?? ''), $brand, $model, $type);
    $sourceUrl = isValidHttpUrl((string)($item['link'] ?? '')) ? (string)$item['link'] : '';
    return [
        'id'         => null,
        'type'       => $type,
        'brand'      => $brand,
        'model'      => $model,
        'price'      => (float)($item['price'] ?? 0),
        'currency'   => $currency,
        'image_url'  => $image,
        'source_url' => $sourceUrl,
        'store_name' => parse_url($sourceUrl, PHP_URL_HOST) ?: 'Online Store',
        'reason'     => componentReasonFromData(array_merge($item, ['type' => $type]), $useCase),
    ];
}

function selectBuildComponentsFromCatalog(
    ?float $budget,
    string $useCase,
    array $specificTypes = [],
    ?string $brandPreference = null
): array {
    $catalog = loadComponentCatalog();
    $selected = [];

    $requiredTypes = !empty($specificTypes)
        ? array_values(array_unique(array_map('mapCategoryToComponentType', $specificTypes)))
        : getRequiredBuildTypes($budget);

    $selectionOrder = ['cpu', 'motherboard', 'ram', 'gpu', 'storage', 'psu', 'case', 'cooler'];
    usort($requiredTypes, function($a, $b) use ($selectionOrder) {
        return (array_search($a, $selectionOrder, true) ?: 99) <=> (array_search($b, $selectionOrder, true) ?: 99);
    });

    foreach ($requiredTypes as $type) {
        $pool = $catalog[$type] ?? [];
        if (empty($pool)) {
            continue;
        }

        $pool = array_values(array_filter($pool, function($item) {
            return isValidHttpUrl((string)($item['link'] ?? ''));
        }));
        if (empty($pool)) {
            continue;
        }

        $pool = filterCandidatesByBrand($pool, $brandPreference);
        $target = getTargetPriceForType($type, $budget, $useCase);
        $choice = selectComponentByTarget($pool, $target, $selected);
        if ($choice) {
            $selected[$type] = $choice;
        }
    }

    if (isset($selected['cpu']) && isset($selected['motherboard']) && !isSocketCompatible($selected['cpu'], $selected['motherboard'])) {
        $mobos = $catalog['motherboard'] ?? [];
        $mobos = array_values(array_filter($mobos, function($mobo) use ($selected) {
            return isValidHttpUrl((string)($mobo['link'] ?? '')) && isSocketCompatible($selected['cpu'], $mobo);
        }));
        if (!empty($mobos)) {
            $selected['motherboard'] = selectComponentByTarget(
                $mobos,
                getTargetPriceForType('motherboard', $budget, $useCase),
                $selected
            );
        }
    }

    if (isset($selected['motherboard']) && isset($selected['ram']) && !isRamCompatibleWithMotherboard($selected['ram'], $selected['motherboard'])) {
        $rams = $catalog['ram'] ?? [];
        $rams = array_values(array_filter($rams, function($ram) use ($selected) {
            return isValidHttpUrl((string)($ram['link'] ?? '')) && isRamCompatibleWithMotherboard($ram, $selected['motherboard']);
        }));
        if (!empty($rams)) {
            $selected['ram'] = selectComponentByTarget(
                $rams,
                getTargetPriceForType('ram', $budget, $useCase),
                $selected
            );
        }
    }

    return $selected;
}

/**
 * Search for PC components online using AI.
 * Uses OpenRouter to generate real, current component recommendations
 * with accurate regional pricing and store availability.
 */
function searchComponentsOnline(
    string $query,
    ?float $budget,
    string $useCase,
    array $location,
    array $specificTypes = [],
    ?string $brandPreference = null
): array {
    $currency = $location['currency'] ?? 'PHP';
    $selected = selectBuildComponentsFromCatalog($budget, $useCase, $specificTypes, $brandPreference);
    $results = [];

    foreach ($selected as $type => $item) {
        $results[] = toRecommendationComponent($item, $type, $currency, $useCase);
    }

    $compatibilityNotes = [];
    if (isset($selected['cpu'], $selected['motherboard']) && isSocketCompatible($selected['cpu'], $selected['motherboard'])) {
        $compatibilityNotes[] = 'CPU and motherboard sockets are matched for compatibility.';
    }
    if (isset($selected['ram'], $selected['motherboard']) && isRamCompatibleWithMotherboard($selected['ram'], $selected['motherboard'])) {
        $compatibilityNotes[] = 'Memory type matches the motherboard memory standard.';
    }
    if (isset($selected['case'], $selected['motherboard']) && caseFitsMotherboard($selected['case'], $selected['motherboard'])) {
        $compatibilityNotes[] = 'Case form factor supports the selected motherboard size.';
    }

    $buildMeta = [
        'build_name' => ucfirst($useCase) . ' Balanced Build',
        'build_summary' => 'Selected from verified catalog entries with real images and product links.',
        'assumptions' => [
            'Recommendations are selected from local JSON component data only.',
            'Prices remain unchanged unless the product page returns a clearly detected current price.'
        ],
        'compatibility_notes' => $compatibilityNotes,
    ];

    if (!empty($results)) {
        $results['_build_meta'] = $buildMeta;
    }

    aiLog('Catalog search returned ' . count($results) . " components for query: $query");
    return $results;
}

/**

 * Generate a complete PC build using AI-powered online search.

 */

function generateBuildOnline(
    float $maxBudget,
    string $useCase,
    array $location,
    array $performanceNeeds = [],
    string $userMessage = ''

): array {
    $components = searchComponentsOnline(
        $userMessage ?: "Build a $useCase PC within budget",
        $maxBudget,
        $useCase,
        $location,
        [], // all component types
        null
    );
    // Filter out non-component entries for calculations
    $compOnly = array_filter($components, fn($v, $k) => is_int($k), ARRAY_FILTER_USE_BOTH);
    $totalCost = array_sum(array_map(fn($c) => (float)($c['price'] ?? 0), $compOnly));
    $utilisation = $maxBudget > 0 ? ($totalCost / $maxBudget) * 100 : 0;
    return [
        'components'         => $components,
        'total_cost'         => round($totalCost, 2),
        'within_budget'      => $totalCost <= $maxBudget * 1.05, // 5% tolerance
        'budget_utilization' => round($utilisation, 1),
        'budget_remaining'   => round($maxBudget - $totalCost, 2),
    ];

}

/**

 * Generate tiered builds (budget, balanced, premium) using AI online search.

 */

function generateTieredBuildsOnline(float $maxBudget, string $useCase, array $location, array $performanceNeeds = [], string $userMessage = ''): array {
    $builds = [];
    $budgetAnalysis = [
        'user_budget'  => $maxBudget,
        'is_feasible'  => true,
        'message'      => 'Budget is sufficient for a build',
        'min_required' => MINIMUM_BUILD_PRICES[$useCase] ?? MINIMUM_BUILD_PRICES['general'],
    ];
    $minRequired = MINIMUM_BUILD_PRICES[$useCase] ?? MINIMUM_BUILD_PRICES['general'];
    $symbol = $location['currency_symbol'] ?? '₱';
    $currency = $location['currency'] ?? 'PHP';
    if ($maxBudget < $minRequired) {
        $budgetAnalysis['is_feasible'] = false;
        $budgetAnalysis['message'] = "A proper $useCase PC build typically starts at around {$symbol}" . number_format($minRequired) . ". Your budget of {$symbol}" . number_format($maxBudget) . " may be tight, but I'll find the best options.";
    }
    $tierBudgets = [
        'budget' => max(1, $maxBudget * 0.70),
        'balanced' => $maxBudget,
        'premium' => max(1, $maxBudget * 1.15),
    ];

    foreach ($tierBudgets as $tierName => $tierBudget) {
        $search = searchComponentsOnline($userMessage, (float)$tierBudget, $useCase, $location);
        $tierComponents = array_values(array_filter($search, fn($v, $k) => is_int($k), ARRAY_FILTER_USE_BOTH));
        if (empty($tierComponents)) {
            continue;
        }

        $totalCost = array_sum(array_map(fn($c) => (float)($c['price'] ?? 0), $tierComponents));
        $meta = $search['_build_meta'] ?? [];

        $builds[$tierName] = [
            'components' => $tierComponents,
            'total_cost' => round($totalCost, 2),
            'within_budget' => $totalCost <= ($tierBudget * 1.12),
            'budget_utilization' => $tierBudget > 0 ? round(($totalCost / $tierBudget) * 100, 1) : 0,
            'budget_remaining' => round($tierBudget - $totalCost, 2),
            'build_name' => ucfirst($useCase) . ' ' . ucfirst($tierName) . ' Build',
            'compatibility_notes' => $meta['compatibility_notes'] ?? [],
            'assumptions' => $meta['assumptions'] ?? ['Tier generated from verified local component data.'],
        ];
    }

    return [
        'builds'          => $builds,
        'budget_analysis' => $budgetAnalysis,
    ];

}

// ---------------------------------------------------------------------------

// Upgrade Suggestions (AI-Powered)

// ---------------------------------------------------------------------------

function detectUpgradeRequest(string $query): array {
    $lower = strtolower($query);
    $upgradeWords = ['upgrade','upgrading','improve','better','future-proof','next level','next step','better option','upgrade path'];
    $isUpgrade = false;
    foreach ($upgradeWords as $w) {
        if (strpos($lower, $w) !== false) {
            $isUpgrade = true;
            break;
        }
    }
    if (!$isUpgrade) return ['is_upgrade_request' => false];
    $mentioned = [];
    $typeKeywords = [
        'cpu' => ['cpu','processor'], 'gpu' => ['gpu','graphics','video card'],
        'ram' => ['ram','memory'], 'storage' => ['storage','ssd','hdd'],
        'motherboard' => ['motherboard','mobo'], 'psu' => ['psu','power supply'],
        'case' => ['case','chassis'], 'cooler' => ['cooler','cooling'],
        'monitor' => ['monitor','display'],
    ];
    foreach ($typeKeywords as $type => $kws) {
        foreach ($kws as $kw) {
            if (strpos($lower, $kw) !== false) {
                $mentioned[] = $type;
                break;
            }
        }
    }
    if (empty($mentioned)) $mentioned = ['all'];
    return ['is_upgrade_request' => true, 'mentioned_components' => $mentioned];

}

/**

 * Extract previous build recommendation from a thread.

 */

function extractPreviousBuild(int $threadId): array {
    $conn = getDBConnection();
    if (!$conn) return ['has_previous_build' => false, 'components' => [], 'budget' => null];
    $sql = "SELECT m.recommendation_id, r.budget_analysis
            FROM messages m
            LEFT JOIN recommendations r ON m.recommendation_id = r.id
            WHERE m.thread_id = ? AND m.data_type = 'recommendation' AND m.recommendation_id IS NOT NULL
            ORDER BY m.created_at DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['has_previous_build' => false, 'components' => [], 'budget' => null];
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['recommendation_id']) {
        return ['has_previous_build' => false, 'components' => [], 'budget' => null];
    }
    $recId = (int)$row['recommendation_id'];
    $budget = null;
    if ($row['budget_analysis']) {
        $ba = json_decode($row['budget_analysis'], true);
        $budget = $ba['user_budget'] ?? null;
    }
    $sql2 = "SELECT component_type as type, brand, model, price, currency, image_url, source_url
             FROM recommendation_components WHERE recommendation_id = ? ORDER BY
             CASE component_type
                 WHEN 'cpu' THEN 1 WHEN 'motherboard' THEN 2 WHEN 'ram' THEN 3
                 WHEN 'gpu' THEN 4 WHEN 'storage' THEN 5 WHEN 'psu' THEN 6
                 WHEN 'case' THEN 7 WHEN 'cooler' THEN 8 ELSE 9
             END";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param('i', $recId);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $components = [];
    while ($c = $result2->fetch_assoc()) {
        $c['price'] = (float)$c['price'];
        $type = $c['type'];
        if (!isset($components[$type])) {
            $components[$type] = $c;
        }
    }
    $stmt2->close();
    return [
        'has_previous_build'  => !empty($components),
        'components'          => $components,
        'budget'              => $budget,
        'recommendation_id'   => $recId,
    ];

}

/**

 * Generate upgrade suggestions using AI-powered online search.

 */

function generateUpgradeSuggestionsOnline(array $currentComponents, array $mentionedTypes, array $location, ?float $budget = null): array {
    if (in_array('all', $mentionedTypes)) {
        $mentionedTypes = array_keys($currentComponents);
    }
    $currency = $location['currency'] ?? 'PHP';
    $catalog = loadComponentCatalog();
    $suggestions = [];
    foreach ($mentionedTypes as $rawType) {
        $type = mapCategoryToComponentType((string)$rawType);
        if (empty($catalog[$type])) {
            continue;
        }

        $current = $currentComponents[$type] ?? [];
        $currentPrice = (float)($current['price'] ?? 0);
        $pool = array_values(array_filter($catalog[$type], function($item) use ($current, $currentPrice) {
            $sameModel = !empty($current['model']) && strcasecmp((string)($item['model'] ?? ''), (string)$current['model']) === 0;
            $hasLink = isValidHttpUrl((string)($item['link'] ?? ''));
            return !$sameModel && $hasLink && (float)($item['price'] ?? 0) > 0;
        }));

        if (empty($pool)) {
            continue;
        }

        usort($pool, function($a, $b) use ($currentPrice) {
            $pa = (float)($a['price'] ?? 0);
            $pb = (float)($b['price'] ?? 0);
            if ($currentPrice > 0) {
                $da = abs($pa - ($currentPrice * 1.20));
                $db = abs($pb - ($currentPrice * 1.20));
                return $da <=> $db;
            }
            return $pa <=> $pb;
        });

        $picked = array_slice($pool, 0, 3);
        $options = [];
        foreach ($picked as $opt) {
            $brand = (string)($opt['brand'] ?? 'Unknown');
            $model = (string)($opt['model'] ?? 'Unknown');
            $sourceUrl = isValidHttpUrl((string)($opt['link'] ?? '')) ? (string)$opt['link'] : '';
            $options[] = [
                'id'         => null,
                'type'       => $type,
                'brand'      => $brand,
                'model'      => $model,
                'price'      => (float)($opt['price'] ?? 0),
                'currency'   => $currency,
                'image_url'  => resolveComponentImageUrl((string)($opt['image_url'] ?? ''), $brand, $model, $type),
                'source_url' => $sourceUrl,
                'store_name' => parse_url($sourceUrl, PHP_URL_HOST) ?: 'Online Store',
                'reason'     => componentReasonFromData(array_merge($opt, ['type' => $type]), 'upgrade'),
                'improvement'=> 'Estimated step-up compared to your current ' . $type . '.',
            ];
        }

        if (empty($options)) {
            continue;
        }

        $suggestions[$type] = [
            'current'         => $current,
            'upgrade_options' => $options,
        ];
    }
    return $suggestions;

}

/**

 * Generate AI-powered alternatives for a component (replaces DB-based alternatives).

 */

function generateAlternativesOnline(array $originalComponent, array $location): array {
    $currency    = $location['currency'] ?? 'PHP';
    $type  = $originalComponent['type'] ?? 'unknown';
    $price = (float)($originalComponent['price'] ?? 0);
    $catalog = loadComponentCatalog();
    $type = mapCategoryToComponentType((string)$type);
    $pool = $catalog[$type] ?? [];
    if (empty($pool)) {
        return [];
    }

    $originalBrand = (string)($originalComponent['brand'] ?? '');
    $originalModel = (string)($originalComponent['model'] ?? '');

    $pool = array_values(array_filter($pool, function($item) use ($originalModel, $originalBrand) {
        $sameModel = $originalModel !== '' && strcasecmp((string)($item['model'] ?? ''), $originalModel) === 0;
        $sameBrand = $originalBrand !== '' && strcasecmp((string)($item['brand'] ?? ''), $originalBrand) === 0;
        return (!$sameModel || !$sameBrand) && isValidHttpUrl((string)($item['link'] ?? ''));
    }));

    usort($pool, function($a, $b) use ($price) {
        return abs(((float)($a['price'] ?? 0)) - $price) <=> abs(((float)($b['price'] ?? 0)) - $price);
    });

    $picked = array_slice($pool, 0, 8);
    $results = [];
    foreach ($picked as $alt) {
        $aBrand = (string)($alt['brand'] ?? 'Unknown');
        $aModel = (string)($alt['model'] ?? 'Unknown');
        $sourceUrl = isValidHttpUrl((string)($alt['link'] ?? '')) ? (string)$alt['link'] : '';
        $results[] = [
            'id'         => null,
            'type'       => $type,
            'brand'      => $aBrand,
            'model'      => $aModel,
            'price'      => (float)($alt['price'] ?? 0),
            'currency'   => $currency,
            'image_url'  => resolveComponentImageUrl((string)($alt['image_url'] ?? ''), $aBrand, $aModel, $type),
            'source_url' => $sourceUrl,
            'store_name' => parse_url($sourceUrl, PHP_URL_HOST) ?: 'Online Store',
            'reason'     => componentReasonFromData(array_merge($alt, ['type' => $type]), 'general'),
        ];
    }
    return $results;

}

// ---------------------------------------------------------------------------
// Image URL Auto-Fetch
// ---------------------------------------------------------------------------

/**
 * Find a product image URL using web search and save it to the database.
 * Uses a simple HTTP request to DuckDuckGo (no API key needed).
 */
function fetchAndSaveImageUrl(array $component): ?string {
    if (!IMAGE_SEARCH_ENABLED) return null;
    $brand = $component['brand'] ?? '';
    $model = $component['model'] ?? '';
    $type  = $component['type'] ?? '';
    $id    = $component['id'] ?? null;
    if (empty($model) || empty($id)) return null;
    // Build search query
    $searchQuery = trim("$brand $model $type product image");
    aiLog("Searching image for: $searchQuery");
    // Try Google Custom Search first if keys are available
    $googleApiKey = getenv('GOOGLE_API_KEY');
    $googleCseId  = getenv('GOOGLE_CSE_ID');
    $imageUrl = null;
    if ($googleApiKey && $googleCseId) {
        $imageUrl = searchGoogleImage($searchQuery, $googleApiKey, $googleCseId);
    }
    // Fallback: simple DuckDuckGo search
    if (!$imageUrl) {
        $imageUrl = searchDuckDuckGoImage($searchQuery);
    }
    // Save to database
    if ($imageUrl && $id) {
        saveComponentImageUrl((int)$id, $imageUrl);
        aiLog("Saved image URL for component #$id: $imageUrl");
    }
    return $imageUrl;
}

function searchGoogleImage(string $query, string $apiKey, string $cseId): ?string {
    $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
        'key'        => $apiKey,
        'cx'         => $cseId,
        'q'          => $query,
        'searchType' => 'image',
        'num'        => 3,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['items'][0]['link'])) {
        return $data['items'][0]['link'];
    }
    return null;
}

function searchDuckDuckGoImage(string $query): ?string {
    // Use DuckDuckGo instant answer API for a quick image
    $url = 'https://api.duckduckgo.com/?' . http_build_query([
        'q'      => $query,
        'format' => 'json',
        'no_html'=> 1,
        't'      => 'smartspecs',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'SmartSpecs/1.0',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['Image'])) {
        return $data['Image'];
    }
    // Try related topics
    if (!empty($data['RelatedTopics'])) {
        foreach ($data['RelatedTopics'] as $topic) {
            if (!empty($topic['Icon']['URL'])) {
                return $topic['Icon']['URL'];
            }
        }
    }
    return null;
}

/**
 * Save an image URL to the component in the database.
 */
function saveComponentImageUrl(int $componentId, string $imageUrl): bool {
    $conn = getDBConnection();
    if (!$conn) return false;
    $stmt = $conn->prepare("UPDATE components SET image_url = ? WHERE id = ? AND (image_url IS NULL OR image_url = '')");
    if (!$stmt) return false;
    $stmt->bind_param('si', $imageUrl, $componentId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Check and fetch missing image URLs for an array of components.
 * Modifies the array in-place.
 */
function ensureImageUrls(array &$components): void {
    foreach ($components as &$comp) {
        if (empty($comp['image_url'])) {
            $url = fetchAndSaveImageUrl($comp);
            if ($url) {
                $comp['image_url'] = $url;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Recommendation DB Storage (matches Python app.py)
// ---------------------------------------------------------------------------

function createRecommendation(string $aiResponse, array $queryAnalysis,
                              int $componentsFound, bool $needsUpdate,
                              array $budgetAnalysis = []): ?int {
    $conn = getDBConnection();
    if (!$conn) return null;
    $sql = "INSERT INTO recommendations (ai_response, query_analysis, components_found, needs_update, budget_analysis) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        aiLog("createRecommendation prepare failed: " . $conn->error, 'ERROR');
        return null;
    }
    $qaJson = json_encode($queryAnalysis);
    $baJson = !empty($budgetAnalysis) ? json_encode($budgetAnalysis) : null;
    $nu = $needsUpdate ? 1 : 0;
    $stmt->bind_param('ssiss', $aiResponse, $qaJson, $componentsFound, $nu, $baJson);
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $stmt->close();
        return $id;
    }
    $stmt->close();
    return null;
}

function addRecommendationComponent(int $recId, array $comp, string $tier = 'balanced'): bool {
    $conn = getDBConnection();
    if (!$conn) return false;
    $sql = "INSERT INTO recommendation_components (recommendation_id, component_type, brand, model, price, currency, image_url, source_url, tier) VALUES (?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $type     = $comp['type'] ?? '';
    $brand    = $comp['brand'] ?? '';
    $model    = $comp['model'] ?? '';
    $price    = (float)($comp['price'] ?? 0);
    $currency = $comp['currency'] ?? 'PHP';
    $imageUrl = $comp['image_url'] ?? null;
    $sourceUrl= $comp['source_url'] ?? null;
    $stmt->bind_param('isssdssss', $recId, $type, $brand, $model, $price, $currency, $imageUrl, $sourceUrl, $tier);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function addRecommendationTier(int $recId, string $tierName, float $totalPrice, int $count): bool {
    $conn = getDBConnection();
    if (!$conn) return false;
    $sql = "INSERT INTO recommendation_tiers (recommendation_id, tier_name, total_price, components_count) VALUES (?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('isdi', $recId, $tierName, $totalPrice, $count);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// ---------------------------------------------------------------------------
// Thread Title Generation (via OpenRouter)
// ---------------------------------------------------------------------------

function generateThreadTitleAI(string $userMessage): string {
    $messages = [
        ['role' => 'system', 'content' => 'Generate a short, descriptive title (max 6 words) for a PC-building conversation thread based on the user\'s first message. Return ONLY the title, nothing else. Use Filipino peso sign ₱ for budgets.'],
        ['role' => 'user', 'content' => $userMessage],
    ];
    $title = callOpenRouter($messages, 0.3, 50);
    $title = trim($title, " \t\n\r\0\x0B\"'");
    if (strlen($title) > 60 || strlen($title) < 3) {
        // Fallback to regex-based title
        return generateThreadTitleFallback($userMessage);
    }
    return $title;
}

function generateThreadTitleFallback(string $msg): string {
    $cleaned = trim(preg_replace('/\s+/', ' ', $msg));
    $words = explode(' ', $cleaned);
    // Extract budget
    $budgetText = '';
    if (preg_match('/₱?\s*([\d,]+)\s*(?:k|K)?/i', $msg, $bm)) {
        $budgetText = '₱' . $bm[1] . ' ';
    }
    // Check for build keywords
    $buildKws = ['build','setup','pc','computer','gaming','workstation'];
    $hasBuild = false;
    foreach ($buildKws as $kw) {
        if (stripos($msg, $kw) !== false) { $hasBuild = true; break; }
    }
    if ($hasBuild && $budgetText) {
        return $budgetText . 'PC Build';
    } elseif ($budgetText) {
        return $budgetText . 'PC Inquiry';
    } elseif (count($words) >= 3) {
        return implode(' ', array_slice($words, 0, 5)) . (count($words) > 5 ? '...' : '');
    }
    return 'PC Build Discussion';
}

// ---------------------------------------------------------------------------
// Main orchestrator: processMessage()
// ---------------------------------------------------------------------------

/**
 * Process a user message and return a structured response.
 * This is the main entry point that replaces the Python /generate endpoint.
 */
function processAIMessage(string $userMessage, ?int $threadId = null, ?array $conversationHistory = []): array {
    $startTime = microtime(true);
    $conversationHistory = $conversationHistory ?? [];
    aiLog("Processing: \"" . substr($userMessage, 0, 120) . "\" (Thread: $threadId)");
    // Step 0: Detect user's location for regional search
    $location = getUserLocation();
    aiLog("User location: {$location['country']} ({$location['country_code']})");
    if (isPriceRefreshRequest($userMessage)) {
        $refreshSummary = refreshComponentPricesFromJson();
        $elapsed = round(microtime(true) - $startTime, 2);
        $message = "Price refresh finished. Checked {$refreshSummary['components_checked']} components across {$refreshSummary['files_scanned']} files. Updated {$refreshSummary['prices_updated']} prices and wrote {$refreshSummary['files_updated']} JSON files. Prices were only changed when a clear price was detected from the product page.";
        return [
            'success' => true,
            'data' => [
                'type' => 'text',
                'ai_message' => $message,
                'query_analysis' => ['intent' => 'price_refresh'],
                'components' => [],
                'multiple_recommendations' => [],
                'budget_analysis' => [],
                'build_info' => [],
                'location' => $location,
                'minimum_build' => [],
                'needs_update' => false,
                'components_found' => 0,
            ],
            'recommendation_id' => null,
            'processing_time' => "{$elapsed}s",
            'timestamp' => date('c'),
        ];
    }
    // Step 1: Analyze the message with OpenRouter
    $analysis = analyzeUserMessage($userMessage, $conversationHistory);
    aiLog("Analysis: " . json_encode($analysis));
    // Merge with regex fallback for budget/components if AI missed them
    $fallback = fallbackParseQuery($userMessage);
    if (!$analysis['budget'] && $fallback['budget']) {
        $analysis['budget'] = $fallback['budget'];
    }
    if (empty($analysis['specific_components']) && !empty($fallback['specific_components'])) {
        $analysis['specific_components'] = $fallback['specific_components'];
    }
    if (empty($analysis['performance_needs']) && !empty($fallback['performance_needs'])) {
        $analysis['performance_needs'] = $fallback['performance_needs'];
        $analysis['use_case'] = $fallback['use_case'];
    }
    // If fallback says it's a build but AI says otherwise, prefer fallback
    if ($fallback['needs_full_build'] && !$analysis['needs_full_build']) {
        $analysis['needs_full_build'] = true;
        if ($analysis['intent'] === 'general_question' || $analysis['intent'] === 'follow_up') {
            $analysis['intent'] = 'build_recommendation';
        }
    }
    $intent = $analysis['intent'] ?? 'general_question';
    $budget = $analysis['budget'] ?? null;
    $useCase = $analysis['use_case'] ?? 'general';
    $needsFullBuild = $analysis['needs_full_build'] ?? false;
    $components     = [];
    $allBuilds      = [];
    $budgetAnalysis = [];
    $upgradeData    = [];
    $responseType   = 'text';
    $recommendationId = null;
    // Step 2: Handle based on intent (using online search instead of DB)
    switch ($intent) {
        case 'build_recommendation':
            if ($budget && $budget > 0) {
                $responseType = 'recommendation';
                $tiered = generateTieredBuildsOnline($budget, $useCase, $location, $analysis['performance_needs'] ?? [], $userMessage);
                $allBuilds = $tiered['builds'];
                $budgetAnalysis = $tiered['budget_analysis'];
                // Use balanced build as primary
                $components = $allBuilds['balanced']['components'] ?? ($allBuilds['budget']['components'] ?? []);
            } elseif ($needsFullBuild) {
                $responseType = 'recommendation';
                // No budget — still try to provide a general recommendation
                $components = searchComponentsOnline(
                    $userMessage, null, $useCase, $location
                );
            }
            break;
        case 'upgrade_suggestion':
            $responseType = 'upgrade_suggestion';
            $upgradeDetection = detectUpgradeRequest($userMessage);
            if ($threadId) {
                $prevBuild = extractPreviousBuild($threadId);
                if ($prevBuild['has_previous_build']) {
                    $upgradeData = generateUpgradeSuggestionsOnline(
                        $prevBuild['components'],
                        $upgradeDetection['mentioned_components'] ?? ['all'],
                        $location,
                        $prevBuild['budget']
                    );
                    // Build flat component list for response
                    $components = [];
                    foreach ($upgradeData as $type => $data) {
                        foreach ($data['upgrade_options'] ?? [] as $opt) {
                            $opt['is_upgrade'] = true;
                            $opt['current_component'] = ($data['current']['brand'] ?? '') . ' ' . ($data['current']['model'] ?? '');
                            $opt['current_price'] = (float)($data['current']['price'] ?? 0);
                            $opt['price_difference'] = (float)($opt['price'] ?? 0) - (float)($data['current']['price'] ?? 0);
                            $opt['price_difference_percent'] = ($data['current']['price'] ?? 0) > 0
                                ? round(($opt['price_difference'] / (float)$data['current']['price']) * 100, 1) : 0;
                            $components[] = $opt;
                        }
                    }
                }
            }
            break;
        case 'component_search':
            $responseType = 'recommendation';
            $components = searchComponentsOnline(
                $userMessage,
                $budget,
                $useCase,
                $location,
                $analysis['specific_components'] ?? [],
                $analysis['brand_preference'] ?? null
            );
            break;
        case 'off_topic':
            // Non-PC/device related query - politely decline
            $responseType = 'text';
            $elapsed = round(microtime(true) - $startTime, 2);
            aiLog("Off-topic request rejected in {$elapsed}s");
            return [
                'success' => true,
                'data' => [
                    'type'                      => 'text',
                    'ai_message'                => "I appreciate your message! However, I'm **SmartSpecs** — a specialized assistant for **PC builds, computer hardware, laptops, and tech devices**.\n\nI'm not able to help with topics outside of that scope, but here's what I **can** help you with:\n\n- **PC Build Recommendations** — Tell me your budget and I'll suggest the best parts\n- **Component Upgrades** — Looking to improve your current setup?\n- **Tips & Tricks** — Overclocking, cable management, optimization, and more\n- **Where to Buy** — I know the best local and online stores\n- **Tech Questions** — DDR4 vs DDR5? Air vs liquid cooling? Just ask!\n\nFeel free to ask me anything about computers and tech! 😊",
                    'query_analysis'            => $analysis,
                    'components'                => [],
                    'multiple_recommendations'  => [],
                    'budget_analysis'           => [],
                    'location'                  => $location,
                    'minimum_build'             => [],
                    'needs_update'              => false,
                    'components_found'          => 0,
                ],
                'recommendation_id' => null,
                'processing_time'   => "{$elapsed}s",
                'timestamp'         => date('c'),
            ];
        case 'tips_and_hacks':
        case 'where_to_buy':
        case 'general_question':
        case 'greeting':
        case 'follow_up':
        default:
            // For follow-ups, check if there was a previous build
            if ($intent === 'follow_up' && $threadId) {
                $prevBuild = extractPreviousBuild($threadId);
                if ($prevBuild['has_previous_build']) {
                    $components = array_values($prevBuild['components']);
                }
            }
            break;
    }
    // Step 3: Generate the AI response text using OpenRouter
    $responseText = generateAIResponseText(
        $userMessage, $analysis, $components, $conversationHistory, $budgetAnalysis, $upgradeData, $location
    );
    // Step 4: Store recommendation in DB
    if (in_array($responseType, ['recommendation', 'upgrade_suggestion']) && !empty($components)) {
        $recommendationId = createRecommendation(
            $responseText, $analysis, count($components), false, $budgetAnalysis
        );
        if ($recommendationId) {
            // Store main (balanced) components — filter out non-component entries
            $dbComponents = array_filter($components, fn($v, $k) => is_int($k), ARRAY_FILTER_USE_BOTH);
            foreach (array_slice($dbComponents, 0, 15) as $comp) {
                addRecommendationComponent($recommendationId, $comp, 'balanced');
            }
            // Store tier data
            foreach ($allBuilds as $tierName => $tierData) {
                if (!empty($tierData['components'])) {
                    addRecommendationTier(
                        $recommendationId,
                        $tierName,
                        $tierData['total_cost'] ?? 0,
                        count($tierData['components'])
                    );
                    foreach ($tierData['components'] as $comp) {
                        addRecommendationComponent($recommendationId, $comp, $tierName);
                    }
                }
            }
        }
    }
    $elapsed = round(microtime(true) - $startTime, 2);
    $compCount = count(array_filter($components, fn($v, $k) => is_int($k), ARRAY_FILTER_USE_BOTH));
    aiLog("Processed in {$elapsed}s — intent=$intent, components=$compCount, recId=$recommendationId");
    // Step 5: Build structured response
    // Extract build metadata from components (if present from searchComponentsOnline)
    $buildMeta = $components['_build_meta'] ?? null;
    $formattedComponents = [];
    foreach ($components as $key => $comp) {
        // Skip non-numeric keys (like _build_meta)
        if (!is_int($key)) continue;
        $formattedComponents[] = [
            'id'         => $comp['id'] ?? null,
            'type'       => $comp['type'] ?? '',
            'brand'      => $comp['brand'] ?? '',
            'model'      => $comp['model'] ?? '',
            'price'      => (float)($comp['price'] ?? 0),
            'currency'   => $comp['currency'] ?? ($location['currency'] ?? 'PHP'),
            'image_url'  => $comp['image_url'] ?? null,
            'source_url' => $comp['source_url'] ?? null,
            'store_name' => $comp['store_name'] ?? null,
            'reason'     => $comp['reason'] ?? null,
            'is_upgrade'        => $comp['is_upgrade'] ?? false,
            'current_component' => $comp['current_component'] ?? null,
            'current_price'     => isset($comp['current_price']) ? (float)$comp['current_price'] : null,
            'price_difference'  => isset($comp['price_difference']) ? (float)$comp['price_difference'] : null,
            'price_difference_percent' => isset($comp['price_difference_percent']) ? (float)$comp['price_difference_percent'] : null,
        ];
    }
    // Format multiple tiers for frontend
    $formattedBuilds = [];
    foreach ($allBuilds as $tierName => $tierData) {
        $tierComponents = [];
        foreach (($tierData['components'] ?? []) as $comp) {
            $tierComponents[] = [
                'id'         => $comp['id'] ?? null,
                'type'       => $comp['type'] ?? '',
                'brand'      => $comp['brand'] ?? '',
                'model'      => $comp['model'] ?? '',
                'price'      => (float)($comp['price'] ?? 0),
                'currency'   => $comp['currency'] ?? ($location['currency'] ?? 'PHP'),
                'image_url'  => $comp['image_url'] ?? null,
                'source_url' => $comp['source_url'] ?? null,
                'store_name' => $comp['store_name'] ?? null,
                'reason'     => $comp['reason'] ?? null,
            ];
        }
        $formattedBuilds[$tierName] = [
            'components'          => $tierComponents,
            'build_name'          => $tierData['build_name'] ?? null,
            'total_cost'          => $tierData['total_cost'] ?? 0,
            'compatibility_notes' => $tierData['compatibility_notes'] ?? [],
            'assumptions'         => $tierData['assumptions'] ?? [],
        ];
    }
    // Collect build-level metadata for the response
    $buildInfo = [];
    if ($buildMeta) {
        $buildInfo = [
            'build_name'          => $buildMeta['build_name'] ?? null,
            'build_summary'       => $buildMeta['build_summary'] ?? null,
            'assumptions'         => $buildMeta['assumptions'] ?? [],
            'compatibility_notes' => $buildMeta['compatibility_notes'] ?? [],
        ];
    }
    return [
        'success' => true,
        'data' => [
            'type'                      => $responseType,
            'ai_message'                => $responseText,
            'query_analysis'            => $analysis,
            'components'                => $formattedComponents,
            'multiple_recommendations'  => $formattedBuilds,
            'budget_analysis'           => $budgetAnalysis,
            'build_info'                => $buildInfo,
            'location'                  => $location,
            'minimum_build'             => [],
            'needs_update'              => false,
            'components_found'          => count($formattedComponents),
        ],
        'recommendation_id' => $recommendationId,
        'processing_time'   => "{$elapsed}s",
        'timestamp'         => date('c'),
    ];
}

// ---------------------------------------------------------------------------
// HTTP Request Handler (when called directly as API endpoint)
// ---------------------------------------------------------------------------
if (basename($_SERVER['SCRIPT_FILENAME']) === 'ai_service.php') {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    // Health check
    if ($action === 'health' || $action === 'status') {
        $conn = getDBConnection();
        echo json_encode([
            'status'   => 'ok',
            'database' => $conn ? 'connected' : 'disconnected',
            'openrouter_configured' => !empty(OPENROUTER_API_KEY),
            'timestamp' => date('c'),
        ]);
        exit;
    }
    if ($action === 'refresh_prices' && $method === 'POST') {
        $summary = refreshComponentPricesFromJson();
        echo json_encode(['success' => true, 'summary' => $summary]);
        exit;
    }
    // Generate title
    if ($action === 'title' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $msg = trim($input['message'] ?? '');
        if (empty($msg)) {
            echo json_encode(['success' => false, 'error' => 'Message is required']);
            exit;
        }
        $title = generateThreadTitleAI($msg);
        echo json_encode(['success' => true, 'title' => $title]);
        exit;
    }
    // Get recommendation data
    if ($action === 'recommendation' && $method === 'GET' && isset($_GET['id'])) {
        $recId = (int)$_GET['id'];
        $conn = getDBConnection();
        if (!$conn) {
            echo json_encode(['success' => false, 'error' => 'DB connection failed']);
            exit;
        }
        // Fetch recommendation
        $stmt = $conn->prepare("SELECT * FROM recommendations WHERE id = ?");
        $stmt->bind_param('i', $recId);
        $stmt->execute();
        $rec = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }
        // Fetch components
        $stmt2 = $conn->prepare("SELECT * FROM recommendation_components WHERE recommendation_id = ?");
        $stmt2->bind_param('i', $recId);
        $stmt2->execute();
        $comps = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
        echo json_encode(['success' => true, 'recommendation' => $rec, 'components' => $comps]);
        exit;
    }
    // Get alternatives (AI-powered online search)
    if ($action === 'alternatives' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        // Accept component info directly (no DB lookup needed)
        $componentInfo = [
            'type'  => $input['component_type'] ?? $input['type'] ?? 'unknown',
            'brand' => $input['brand'] ?? 'Unknown',
            'model' => $input['model'] ?? 'Unknown',
            'price' => (float)($input['price'] ?? 0),
        ];
        if ($componentInfo['model'] === 'Unknown') {
            echo json_encode(['success' => false, 'error' => 'Component details required (brand, model, price)']);
            exit;
        }
        $location = getUserLocation();
        $alts = generateAlternativesOnline($componentInfo, $location);
        echo json_encode([
            'success'            => true,
            'original_component' => $componentInfo,
            'alternatives'       => array_slice($alts, 0, 8),
            'compatibility_note' => 'Alternatives are based on similar specs and price range. Always verify compatibility before purchasing.',
        ]);
        exit;
    }
    // Main generate endpoint
    if ($method === 'POST' && ($action === '' || $action === 'generate')) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            exit;
        }
        $msg      = trim($input['message'] ?? '');
        $history  = $input['history'] ?? [];
        $threadId = $input['thread_id'] ?? null;
        if (empty($msg)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is required']);
            exit;
        }
        $result = processAIMessage($msg, $threadId ? (int)$threadId : null, $history);
        echo json_encode($result);
        exit;
    }
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
