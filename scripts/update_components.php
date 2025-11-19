<?php
/**
 * update_components.php - Direct PCPartPicker JSON Dataset Upload
 * Uploads all records from JSON files directly to database
 */

ini_set('max_execution_time', 1800);
ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Manila');

// Load environment variables from .env file (if exists) for local development
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    // @phpstan-ignore-next-line
    if (class_exists('Dotenv\Dotenv')) {
        // @phpstan-ignore-next-line
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
}

// Database configuration - Use environment variables (no hardcoded credentials)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'defaultdb');

// === PCPartPicker Configuration ===
define('PCPARTPICKER_JSON_DIR', __DIR__ . '/pcpartpicker_json/');
define('USD_TO_PHP_RATE', 56.0);
define('VERIFIED_RECORDS_FILE', __DIR__ . '/verified_components.json');

// === CATEGORY VALIDATION RULES ===
class CategoryValidator {
    
    private static $rules = [
        'cpu' => [
            'keywords' => [
                'required' => ['processor', 'cpu', 'ryzen', 'core i', 'threadripper', 'xeon', 'pentium', 'celeron', 'athlon'],
                'brands' => ['AMD', 'Intel'],
                'exclude' => ['cooler', 'fan', 'thermal', 'paste', 'bracket', 'motherboard', 'combo']
            ],
            'price_range' => ['min' => 2000, 'max' => 150000],
            'model_patterns' => [
                '/ryzen\s+[3579]\s+\d{4}/i',
                '/core\s+i[3579]-\d{4,5}/i',
                '/threadripper/i',
                '/xeon/i',
                '/pentium/i',
                '/celeron/i',
                '/athlon/i'
            ]
        ],
        'gpu' => [
            'keywords' => [
                'required' => ['graphics', 'video card', 'gpu', 'geforce', 'radeon', 'rtx', 'gtx', 'rx'],
                'brands' => ['NVIDIA', 'AMD', 'ASUS', 'MSI', 'Gigabyte', 'EVGA', 'Zotac', 'Sapphire', 'PowerColor', 'XFX', 'Palit', 'Gainward', 'Galax', 'Inno3D'],
                'exclude' => ['cpu', 'processor', 'motherboard', 'cable', 'adapter', 'riser']
            ],
            'price_range' => ['min' => 3000, 'max' => 250000],
            'model_patterns' => [
                '/rtx\s*\d{4}(\s*ti|\s*super)?/i',
                '/gtx\s*\d{4}(\s*ti)?/i',
                '/rx\s*\d{4}(\s*xt)?/i',
                '/radeon\s+(rx|vega)/i',
                '/geforce/i'
            ]
        ],
        'motherboard' => [
            'keywords' => [
                'required' => ['motherboard', 'mainboard', 'mobo', 'chipset', 'socket'],
                'brands' => ['ASUS', 'MSI', 'Gigabyte', 'ASRock', 'EVGA', 'Biostar'],
                'chipsets' => ['B550', 'B650', 'X570', 'X670', 'A620', 'Z690', 'Z790', 'B660', 'B760', 'H610', 'H670'],
                'exclude' => ['cpu', 'gpu', 'cooler', 'cable', 'bracket']
            ],
            'price_range' => ['min' => 3000, 'max' => 100000],
            'model_patterns' => [
                '/[ABX]\d{3,4}/i',
                '/prime|rog|tuf|gaming|pro/i',
                '/socket\s*(am[45]|lga\s*\d{4})/i'
            ]
        ],
        'ram' => [
            'keywords' => [
                'required' => ['memory', 'ram', 'ddr', 'dimm'],
                'brands' => ['Corsair', 'G.Skill', 'Kingston', 'Team', 'Crucial', 'ADATA', 'Patriot', 'HyperX'],
                'specs' => ['ddr4', 'ddr5', 'gb', '8gb', '16gb', '32gb', '64gb'],
                'exclude' => ['storage', 'ssd', 'hdd', 'card reader', 'flash drive']
            ],
            'price_range' => ['min' => 800, 'max' => 80000],
            'model_patterns' => [
                '/ddr[45]/i',
                '/\d+gb/i',
                '/\d{4}mhz/i',
                '/(vengeance|trident|fury|elite|ripjaws)/i'
            ]
        ],
        'storage' => [
            'keywords' => [
                'required' => ['ssd', 'hdd', 'hard drive', 'nvme', 'solid state', 'storage'],
                'brands' => ['Samsung', 'Western Digital', 'WD', 'Seagate', 'Crucial', 'Kingston', 'ADATA', 'Corsair', 'Sandisk'],
                'specs' => ['nvme', 'sata', 'm.2', '2.5', '3.5', 'pcie'],
                'exclude' => ['enclosure', 'dock', 'adapter', 'cable', 'case']
            ],
            'price_range' => ['min' => 800, 'max' => 80000],
            'model_patterns' => [
                '/\d+gb|\d+tb/i',
                '/nvme|sata|m\.2/i',
                '/ssd|hdd/i',
                '/gen[34]/i'
            ]
        ],
        'external-storage' => [
            'keywords' => [
                'required' => ['external', 'portable', 'usb drive', 'flash drive', 'external hdd', 'external ssd'],
                'brands' => ['Samsung', 'Western Digital', 'WD', 'Seagate', 'Crucial', 'Kingston', 'ADATA', 'Corsair', 'Sandisk', 'Toshiba', 'LaCie'],
                'specs' => ['usb', 'portable', 'external'],
                'exclude' => ['internal', 'nvme', 'm.2']
            ],
            'price_range' => ['min' => 500, 'max' => 50000],
            'model_patterns' => [
                '/external/i',
                '/portable/i',
                '/usb\s*drive/i',
                '/flash\s*drive/i'
            ]
        ],
        'psu' => [
            'keywords' => [
                'required' => ['power supply', 'psu', 'watt', 'modular'],
                'brands' => ['Corsair', 'Seasonic', 'EVGA', 'Cooler Master', 'Thermaltake', 'FSP', 'Silverstone', 'Be Quiet'],
                'specs' => ['bronze', 'gold', 'platinum', 'titanium', '80 plus', 'modular'],
                'exclude' => ['cable', 'adapter', 'ups', 'surge']
            ],
            'price_range' => ['min' => 1500, 'max' => 50000],
            'model_patterns' => [
                '/\d{3,4}w/i',
                '/(bronze|gold|platinum|titanium)/i',
                '/80\s*plus/i',
                '/(modular|semi-modular)/i'
            ]
        ],
        'case' => [
            'keywords' => [
                'required' => ['case', 'chassis', 'tower', 'cabinet'],
                'brands' => ['Corsair', 'NZXT', 'Cooler Master', 'Thermaltake', 'Fractal Design', 'Phanteks', 'Lian Li', 'Deepcool'],
                'specs' => ['atx', 'matx', 'itx', 'mid tower', 'full tower'],
                'exclude' => ['fan', 'cooler', 'psu', 'hard drive']
            ],
            'price_range' => ['min' => 1000, 'max' => 50000],
            'model_patterns' => [
                '/(atx|matx|itx)/i',
                '/(mid|full|mini)\s*tower/i',
                '/chassis/i'
            ]
        ],
        'case-accessory' => [
            'keywords' => [
                'required' => ['led', 'rgb', 'lighting', 'controller', 'hub', 'bracket', 'adapter'],
                'brands' => ['NZXT', 'Corsair', 'Cooler Master', 'Thermaltake', 'Phanteks', 'Lian Li', 'Deepcool'],
                'specs' => ['rgb', 'led', 'controller', 'hub'],
                'exclude' => ['fan', 'cooler', 'psu', 'case']
            ],
            'price_range' => ['min' => 200, 'max' => 20000],
            'model_patterns' => [
                '/hue|rgb|led|lighting/i',
                '/controller|hub/i',
                '/bracket|adapter/i'
            ]
        ],
        'case-fan' => [
            'keywords' => [
                'required' => ['fan', 'cooling fan', 'case fan'],
                'brands' => ['Corsair', 'Noctua', 'Cooler Master', 'Thermaltake', 'be quiet!', 'Arctic', 'Lian Li', 'Deepcool'],
                'specs' => ['fan', 'rpm', 'airflow', 'pwm'],
                'exclude' => ['cpu cooler', 'liquid cooler', 'heatsink']
            ],
            'price_range' => ['min' => 200, 'max' => 10000],
            'model_patterns' => [
                '/fan/i',
                '/\d+mm/i',
                '/rgb|led/i',
                '/pwm/i'
            ]
        ],
        'cooler' => [
            'keywords' => [
                'required' => ['cooler', 'heatsink', 'thermal', 'liquid cooler', 'aio'],
                'brands' => ['Noctua', 'Cooler Master', 'Corsair', 'NZXT', 'be quiet!', 'Arctic', 'Deepcool', 'Thermaltake'],
                'specs' => ['cooler', 'aio', 'liquid', 'heatsink'],
                'exclude' => ['case', 'fan', 'thermal paste']
            ],
            'price_range' => ['min' => 500, 'max' => 30000],
            'model_patterns' => [
                '/cooler/i',
                '/aio|liquid/i',
                '/heatsink/i'
            ]
        ],
        'monitor' => [
            'keywords' => [
                'required' => ['monitor', 'display', 'screen', 'lcd', 'led'],
                'brands' => ['ASUS', 'Acer', 'Dell', 'Samsung', 'LG', 'BenQ', 'MSI', 'ViewSonic', 'AOC'],
                'specs' => ['monitor', 'display', 'inch', 'hz', 'refresh rate'],
                'exclude' => ['tv', 'projector', 'stand']
            ],
            'price_range' => ['min' => 3000, 'max' => 150000],
            'model_patterns' => [
                '/monitor|display/i',
                '/\d+"|\d+inch/i',
                '/\d+hz/i'
            ]
        ],
        'headphones' => [
            'keywords' => [
                'required' => ['headphone', 'headset', 'earphone', 'earcup'],
                'brands' => ['Razer', 'Logitech', 'SteelSeries', 'HyperX', 'Corsair', 'Sennheiser', 'Audio-Technica', 'Beyerdynamic'],
                'specs' => ['headphone', 'headset', 'audio'],
                'exclude' => ['speaker', 'microphone only']
            ],
            'price_range' => ['min' => 500, 'max' => 50000],
            'model_patterns' => [
                '/headphone|headset/i',
                '/audio|sound/i',
                '/gaming\s*headset/i'
            ]
        ],
        'keyboard' => [
            'keywords' => [
                'required' => ['keyboard', 'mechanical keyboard'],
                'brands' => ['Razer', 'Logitech', 'Corsair', 'SteelSeries', 'HyperX', 'Cooler Master', 'Ducky', 'Keychron'],
                'specs' => ['keyboard', 'mechanical', 'rgb'],
                'exclude' => ['mouse', 'keycap', 'switch']
            ],
            'price_range' => ['min' => 500, 'max' => 30000],
            'model_patterns' => [
                '/keyboard/i',
                '/mechanical/i',
                '/rgb/i'
            ]
        ],
        'mouse' => [
            'keywords' => [
                'required' => ['mouse', 'gaming mouse'],
                'brands' => ['Razer', 'Logitech', 'SteelSeries', 'Corsair', 'HyperX', 'Cooler Master'],
                'specs' => ['mouse', 'gaming mouse', 'dpi'],
                'exclude' => ['keyboard', 'pad', 'mat']
            ],
            'price_range' => ['min' => 300, 'max' => 15000],
            'model_patterns' => [
                '/mouse/i',
                '/gaming\s*mouse/i',
                '/\d+dpi/i'
            ]
        ],
        'optical-drive' => [
            'keywords' => [
                'required' => ['optical', 'dvd', 'blu-ray', 'cd', 'burner'],
                'brands' => ['LG', 'ASUS', 'Pioneer', 'Samsung'],
                'specs' => ['dvd', 'blu-ray', 'cd', 'burner'],
                'exclude' => ['external', 'case', 'software']
            ],
            'price_range' => ['min' => 1000, 'max' => 15000],
            'model_patterns' => [
                '/dvd|blu-ray|cd/i',
                '/burner|writer/i',
                '/optical/i'
            ]
        ],
        'speakers' => [
            'keywords' => [
                'required' => ['speaker', 'soundbar', 'woofer'],
                'brands' => ['Logitech', 'Creative', 'Bose', 'JBL', 'Edifier', 'Razer'],
                'specs' => ['speaker', 'soundbar', 'woofer'],
                'exclude' => ['headphone', 'microphone']
            ],
            'price_range' => ['min' => 500, 'max' => 50000],
            'model_patterns' => [
                '/speaker/i',
                '/soundbar/i',
                '/\d+\.\d/i' // 2.0, 2.1, 5.1 etc.
            ]
        ],
        'ups' => [
            'keywords' => [
                'required' => ['ups', 'uninterruptible', 'battery backup'],
                'brands' => ['APC', 'CyberPower', 'Eaton', 'Tripp Lite'],
                'specs' => ['ups', 'uninterruptible', 'va', 'watt'],
                'exclude' => ['psu', 'battery', 'adapter']
            ],
            'price_range' => ['min' => 2000, 'max' => 100000],
            'model_patterns' => [
                '/ups/i',
                '/uninterruptible/i',
                '/\d+va/i'
            ]
        ],
        'webcam' => [
            'keywords' => [
                'required' => ['webcam', 'camera', 'web camera'],
                'brands' => ['Logitech', 'Microsoft', 'Razer', 'Creative', 'AverMedia'],
                'specs' => ['webcam', 'camera', '1080p', '4k'],
                'exclude' => ['security camera', 'action camera']
            ],
            'price_range' => ['min' => 500, 'max' => 20000],
            'model_patterns' => [
                '/webcam/i',
                '/camera/i',
                '/\d+p|4k/i'
            ]
        ]
    ];

    // Comprehensive brand mappings (extended for new categories)
    private static $brandMappings = [
        'ASUS' => ['asus', 'tuf', 'rog', 'strix', 'prime', 'proart', 'dual', 'phoenix'],
        'MSI' => ['msi', 'gaming x', 'ventus', 'suprim', 'mech', 'armor'],
        'Gigabyte' => ['gigabyte', 'aorus', 'gaming oc', 'eagle', 'windforce'],
        'ASRock' => ['asrock', 'phantom', 'taichi', 'steel legend'],
        'EVGA' => ['evga', 'ftw3', 'xc3'],
        'Zotac' => ['zotac', 'trinity', 'amp'],
        'Sapphire' => ['sapphire', 'nitro', 'pulse'],
        'PowerColor' => ['powercolor', 'red devil', 'red dragon'],
        'XFX' => ['xfx', 'speedster', 'qick'],
        'Palit' => ['palit', 'gamerock', 'gamingpro'],
        'AMD' => ['amd', 'ryzen', 'radeon', 'athlon', 'threadripper'],
        'Intel' => ['intel', 'core', 'pentium', 'celeron', 'xeon'],
        'NVIDIA' => ['nvidia', 'geforce'],
        'Corsair' => ['corsair', 'vengeance', 'dominator', 'icue'],
        'G.Skill' => ['g.skill', 'g skill', 'gskill', 'trident', 'ripjaws'],
        'Kingston' => ['kingston', 'hyperx', 'fury'],
        'Team' => ['team', 'team group', 't-force', 'elite'],
        'Crucial' => ['crucial', 'ballistix'],
        'ADATA' => ['adata', 'xpg', 'spectrix'],
        'Samsung' => ['samsung', '980', '990'],
        'Western Digital' => ['western digital', 'wd', 'wd_black', 'wd black', 'wd blue'],
        'Seagate' => ['seagate', 'barracuda', 'firecuda'],
        'Sandisk' => ['sandisk', 'ultra', 'extreme'],
        'Seasonic' => ['seasonic', 'focus', 'prime'],
        'Cooler Master' => ['cooler master', 'masterbox', 'masterwatt'],
        'Thermaltake' => ['thermaltake', 'toughpower', 'smart'],
        'FSP' => ['fsp', 'hydro'],
        'Be Quiet' => ['be quiet', 'be quiet!', 'pure power', 'straight power'],
        'NZXT' => ['nzxt', 'h510', 'h710'],
        'Fractal Design' => ['fractal', 'fractal design', 'define', 'meshify'],
        'Phanteks' => ['phanteks', 'eclipse'],
        'Lian Li' => ['lian li', 'lian-li', 'o11'],
        'Deepcool' => ['deepcool', 'matrexx'],
        'Noctua' => ['noctua'],
        'Arctic' => ['arctic'],
        'Razer' => ['razer', 'blackshark', 'deathadder', 'blackwidow'],
        'Logitech' => ['logitech', 'g pro', 'g305', 'g502', 'g903'],
        'SteelSeries' => ['steelseries', 'arctis', 'apex'],
        'HyperX' => ['hyperx', 'cloud'],
        'Acer' => ['acer', 'predator', 'nitro'],
        'Dell' => ['dell', 'alienware'],
        'LG' => ['lg'],
        'BenQ' => ['benq'],
        'ViewSonic' => ['viewsonic'],
        'AOC' => ['aoc'],
        'Creative' => ['creative', 'labs', 'pebble'],
        'APC' => ['apc'],
        'CyberPower' => ['cyberpower'],
        'Microsoft' => ['microsoft']
    ];

    /**
     * Validate if product belongs to specified category
     */
    public static function validate($category, $model, $price) {
        if (!isset(self::$rules[$category])) {
            return ['valid' => false, 'reason' => 'Invalid category'];
        }

        $rules = self::$rules[$category];
        $modelLower = strtolower($model);

        // 1. Price range validation (skip if price is 0)
        if ($price > 0 && ($price < $rules['price_range']['min'] || $price > $rules['price_range']['max'])) {
            return [
                'valid' => false, 
                'reason' => "Price ₱{$price} outside {$category} range (₱{$rules['price_range']['min']}-₱{$rules['price_range']['max']})"
            ];
        }

        // 2. Check for excluded keywords (highest priority)
        if (isset($rules['keywords']['exclude'])) {
            foreach ($rules['keywords']['exclude'] as $exclude) {
                if (stripos($modelLower, $exclude) !== false) {
                    return ['valid' => false, 'reason' => "Contains excluded keyword: {$exclude}"];
                }
            }
        }

        // 3. Check for required keywords
        $hasRequired = false;
        if (isset($rules['keywords']['required'])) {
            foreach ($rules['keywords']['required'] as $required) {
                if (stripos($modelLower, $required) !== false) {
                    $hasRequired = true;
                    break;
                }
            }
        }

        // 4. Check model patterns
        $matchesPattern = false;
        if (isset($rules['model_patterns'])) {
            foreach ($rules['model_patterns'] as $pattern) {
                if (preg_match($pattern, $model)) {
                    $matchesPattern = true;
                    break;
                }
            }
        }

        // 5. Check for category-specific specs
        $hasSpec = false;
        if (isset($rules['keywords']['specs'])) {
            foreach ($rules['keywords']['specs'] as $spec) {
                if (stripos($modelLower, $spec) !== false) {
                    $hasSpec = true;
                    break;
                }
            }
        }

        // 6. Validate brand is appropriate for category
        $brand = self::extractBrand($model);
        $validBrand = false;
        if (isset($rules['keywords']['brands'])) {
            foreach ($rules['keywords']['brands'] as $allowedBrand) {
                if (stripos($brand, $allowedBrand) !== false) {
                    $validBrand = true;
                    break;
                }
            }
        }

        // Final validation logic - more lenient for dataset import
        if ($hasRequired && $matchesPattern) {
            return ['valid' => true, 'brand' => $brand];
        } elseif ($matchesPattern && $validBrand) {
            return ['valid' => true, 'brand' => $brand];
        } elseif ($hasRequired && $hasSpec) {
            return ['valid' => true, 'brand' => $brand];
        } elseif ($hasRequired) {
            return ['valid' => true, 'brand' => $brand];
        }

        return [
            'valid' => false, 
            'reason' => "Does not meet {$category} validation criteria (required: {$hasRequired}, pattern: {$matchesPattern}, brand: {$validBrand})"
        ];
    }

    /**
     * Extract and normalize brand from model name
     */
    public static function extractBrand($model) {
        $modelLower = strtolower($model);
        
        foreach (self::$brandMappings as $brand => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($modelLower, strtolower($keyword)) !== false) {
                    return $brand;
                }
            }
        }
        
        // Fallback: first word
        $words = preg_split('/[\s\-_]+/', trim($model));
        return !empty($words) ? ucfirst($words[0]) : 'Unknown';
    }

    /**
     * Auto-detect correct category for a product
     */
    public static function detectCategory($model, $price) {
        $scores = [];
        
        foreach (self::$rules as $category => $rules) {
            $score = 0;
            $modelLower = strtolower($model);
            
            // Check required keywords
            if (isset($rules['keywords']['required'])) {
                foreach ($rules['keywords']['required'] as $keyword) {
                    if (stripos($modelLower, $keyword) !== false) {
                        $score += 3;
                    }
                }
            }
            
            // Check patterns
            if (isset($rules['model_patterns'])) {
                foreach ($rules['model_patterns'] as $pattern) {
                    if (preg_match($pattern, $model)) {
                        $score += 5;
                    }
                }
            }
            
            // Check specs
            if (isset($rules['keywords']['specs'])) {
                foreach ($rules['keywords']['specs'] as $spec) {
                    if (stripos($modelLower, $spec) !== false) {
                        $score += 2;
                    }
                }
            }
            
            // Penalty for excluded keywords
            if (isset($rules['keywords']['exclude'])) {
                foreach ($rules['keywords']['exclude'] as $exclude) {
                    if (stripos($modelLower, $exclude) !== false) {
                        $score -= 10;
                    }
                }
            }
            
            // Price range score (skip if price is 0)
            if ($price > 0 && $price >= $rules['price_range']['min'] && $price <= $rules['price_range']['max']) {
                $score += 1;
            }
            
            $scores[$category] = $score;
        }
        
        arsort($scores);
        $topCategory = array_key_first($scores);
        
        return $scores[$topCategory] > 2 ? $topCategory : null; // Lower threshold for dataset
    }
}

class VerifiedRecordsSaver {
    
    /**
     * Save verified records to JSON file
     */
    public static function saveVerifiedRecords($verifiedData, $filename = null) {
        if (empty($verifiedData)) {
            echo "❌ No verified data to save\n";
            return false;
        }
        
        $filename = $filename ?: VERIFIED_RECORDS_FILE;
        
        try {
            // Prepare data for JSON export
            $exportData = [
                'metadata' => [
                    'export_date' => date('Y-m-d H:i:s'),
                    'total_records' => count($verifiedData),
                    'source' => 'pcpartpicker'
                ],
                'records' => []
            ];
            
            foreach ($verifiedData as $record) {
                $exportData['records'][] = [
                    'type' => $record['type'],
                    'brand' => $record['brand'],
                    'model' => $record['model'],
                    'price_php' => $record['price'],
                    'currency' => 'PHP',
                    'image_url' => $record['image_url'] ?? '',
                    'source_url' => $record['source_url'] ?? '',
                    'specs' => $record['specs'] ? json_decode($record['specs'], true) : null,
                    'validation_timestamp' => date('Y-m-d H:i:s')
                ];
            }
            
            // Save to JSON file with pretty printing
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            if (file_put_contents($filename, $jsonContent)) {
                echo "✅ Verified records saved to: " . basename($filename) . "\n";
                echo "   📊 Total records: " . count($verifiedData) . "\n";
                echo "   💾 File size: " . self::formatFileSize(strlen($jsonContent)) . "\n";
                return true;
            } else {
                echo "❌ Failed to write verified records to file: " . basename($filename) . "\n";
                return false;
            }
            
        } catch (Exception $e) {
            echo "❌ Error saving verified records: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Format file size for display
     */
    private static function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Load verified records from JSON file
     */
    public static function loadVerifiedRecords($filename = null) {
        $filename = $filename ?: VERIFIED_RECORDS_FILE;
        
        if (!file_exists($filename)) {
            echo "❌ Verified records file not found: " . basename($filename) . "\n";
            return [];
        }
        
        try {
            $jsonContent = file_get_contents($filename);
            $data = json_decode($jsonContent, true);
            
            if (!$data || !isset($data['records'])) {
                echo "❌ Invalid verified records file format\n";
                return [];
            }
            
            echo "✅ Loaded " . count($data['records']) . " verified records from: " . basename($filename) . "\n";
            return $data['records'];
            
        } catch (Exception $e) {
            echo "❌ Error loading verified records: " . $e->getMessage() . "\n";
            return [];
        }
    }
}

// === PCPARTPICKER JSON PROCESSOR ===
class PCPartPickerJSONProcessor {
    
    /**
     * Process JSON files from PCPartPicker dataset
     */
    public static function processJSONFiles() {
        echo "🔍 Processing PCPartPicker JSON files...\n";
        
        $jsonFiles = self::findJSONFiles(PCPARTPICKER_JSON_DIR);
        $allProducts = [];
        
        foreach ($jsonFiles as $file) {
            echo "  📁 Processing: " . basename($file) . "\n";
            $category = self::extractCategoryFromFilename($file);
            $products = self::parseJSONFile($file, $category);
            
            if (!empty($products)) {
                $allProducts = array_merge($allProducts, $products);
                echo "    ✅ Found " . count($products) . " products for $category\n";
            }
        }
        
        echo "📊 Total products from JSON: " . count($allProducts) . "\n";
        return $allProducts;
    }
    
    /**
     * Find all JSON files in directory
     */
    private static function findJSONFiles($directory) {
        if (!is_dir($directory)) {
            echo "❌ JSON directory not found: $directory\n";
            echo "💡 Please create directory: $directory and add your JSON files\n";
            return [];
        }
        
        $files = [];
        $items = scandir($directory);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $directory . $item;
            if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'json') {
                $files[] = $path;
            }
        }
        
        if (empty($files)) {
            echo "❌ No JSON files found in: $directory\n";
            echo "💡 Please add JSON files to the directory\n";
        }
        
        return $files;
    }
    
    /**
     * Map category to database ENUM value
     */
    public static function mapCategoryToDatabaseType($category) {
        // Map filename categories to database ENUM values
        // Database ENUM: 'cpu','gpu','ram','storage','motherboard','psu','case','cooler','monitor','peripheral'
        $categoryMap = [
            'cpu' => 'cpu',
            'gpu' => 'gpu',
            'ram' => 'ram',
            'memory' => 'ram',
            'storage' => 'storage',
            'internal-hard-drive' => 'storage',
            'external-hard-drive' => 'storage',
            'external-storage' => 'storage',
            'motherboard' => 'motherboard',
            'psu' => 'psu',
            'power-supply' => 'psu',
            'case' => 'case',
            'cooler' => 'cooler',
            'cpu-cooler' => 'cooler',
            'monitor' => 'monitor',
            // All peripherals map to 'peripheral'
            'case-fan' => 'peripheral',
            'case-accessory' => 'peripheral',
            'fan-controller' => 'peripheral',
            'keyboard' => 'peripheral',
            'mouse' => 'peripheral',
            'speakers' => 'peripheral',
            'headphones' => 'peripheral',
            'headset' => 'peripheral',
            'webcam' => 'peripheral',
            'wired-network-card' => 'peripheral',
            'wireless-network-card' => 'peripheral',
            'sound-card' => 'peripheral',
            'optical-drive' => 'peripheral',
            'thermal-paste' => 'peripheral',
            'ups' => 'peripheral',
            'os' => 'peripheral'
        ];
        
        return $categoryMap[$category] ?? 'peripheral'; // Default to peripheral if unknown
    }
    
    /**
     * Extract category from filename
     */
    private static function extractCategoryFromFilename($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = strtolower($name);
        
        $categoryMap = [
            // IMPORTANT: Longer/more specific matches must come FIRST
            // This prevents "cpu" from matching "cpu-cooler" files
            'cpu-cooler' => 'cooler',
            'case-fan' => 'case-fan',
            'case-accessory' => 'case-accessory',
            'external-hard-drive' => 'external-storage',
            'external-storage' => 'external-storage',
            'internal-hard-drive' => 'storage',
            'hard-drive' => 'storage',
            'hard_drive' => 'storage',
            'video-card' => 'gpu',
            'graphics-card' => 'gpu',
            'video_cards' => 'gpu',
            'power-supply' => 'psu',
            'power_supply' => 'psu',
            'memory_ram' => 'ram',
            'optical-drive' => 'optical-drive',
            'uninterruptible' => 'ups',
            // Generic matches (must come after specific ones)
            'cpu' => 'cpu',
            'processor' => 'cpu',
            'gpu' => 'gpu',
            'motherboard' => 'motherboard',
            'mainboard' => 'motherboard',
            'mobo' => 'motherboard',
            'motherboards' => 'motherboard',
            'ram' => 'ram',
            'memory' => 'ram',
            'storage' => 'storage',
            'hdd' => 'storage',
            'psu' => 'psu',
            'case' => 'case',
            'chassis' => 'case',
            'cabinet' => 'case',
            'cases' => 'case',
            'cooler' => 'cooler',
            'monitor' => 'monitor',
            'display' => 'monitor',
            'headphones' => 'headphones',
            'headset' => 'headphones',
            'keyboard' => 'keyboard',
            'mouse' => 'mouse',
            'optical' => 'optical-drive',
            'speakers' => 'speakers',
            'speaker' => 'speakers',
            'ups' => 'ups',
            'webcam' => 'webcam',
            'camera' => 'webcam'
        ];
        
        // Check for exact match first (most reliable)
        if (isset($categoryMap[$name])) {
            return $categoryMap[$name];
        }
        
        // Then check for substring matches (longer keys first)
        $keys = array_keys($categoryMap);
        // Sort by length descending so longer matches are checked first
        usort($keys, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($keys as $key) {
            if (strpos($name, $key) !== false) {
                return $categoryMap[$key];
            }
        }
        
        // Default to auto-detection
        return null;
    }
    
    /**
     * Parse JSON file and extract products
     */
    private static function parseJSONFile($filePath, $category = null) {
        $jsonContent = file_get_contents($filePath);
        if (!$jsonContent) {
            echo "    ❌ Could not read file: " . basename($filePath) . "\n";
            return [];
        }
        
        $data = json_decode($jsonContent, true);
        if (!$data || !is_array($data)) {
            echo "    ❌ Invalid JSON in file: " . basename($filePath) . "\n";
            return [];
        }
        
        $products = [];
        
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            
            $product = self::parseProductData($item, $category);
            if ($product) {
                $products[] = $product;
            }
        }
        
        return $products;
    }
    
    /**
     * Parse individual product data from JSON
     */
    private static function parseProductData($item, $category = null) {
        // Extract basic information with multiple possible field names
        $name = $item['name'] ?? $item['title'] ?? $item['model'] ?? $item['product'] ?? '';
        $price = $item['price'] ?? $item['usd_price'] ?? $item['cost'] ?? $item['usd'] ?? 0;
        $brand = $item['brand'] ?? $item['manufacturer'] ?? $item['maker'] ?? '';
        
        if (empty($name)) {
            return null;
        }
        
        // Convert price to PHP if in USD
        if ($price > 0) {
            $pricePHP = floatval($price) * USD_TO_PHP_RATE;
        } else {
            $pricePHP = 0;
        }
        
        // Auto-detect category if not provided
        if (!$category) {
            $category = CategoryValidator::detectCategory($name, $pricePHP);
        }
        
        if (!$category) {
            // If still no category, skip the product
            return null;
        }
        
        // Extract brand if not provided
        if (empty($brand)) {
            $brand = CategoryValidator::extractBrand($name);
        }
        
        // Extract specifications
        $specs = self::extractSpecifications($item);
        
        // Extract image URL if available
        $imageUrl = $item['image'] ?? $item['image_url'] ?? $item['img'] ?? '';
        
        // Extract source URL if available
        $sourceUrl = $item['url'] ?? $item['link'] ?? $item['source_url'] ?? '';
        
        return [
            'model' => self::cleanModelName($name),
            'price' => $pricePHP,
            'brand' => $brand,
            'category' => $category,
            'specs' => $specs,
            'image_url' => $imageUrl,
            'source_url' => $sourceUrl,
            'raw_data' => $item // Keep raw data for reference
        ];
    }
    
    /**
     * Extract specifications from product data
     */
    private static function extractSpecifications($item) {
        $specs = [];
        
        // Common specification fields
        $specFields = [
            'speed', 'cores', 'threads', 'chipset', 'memory', 'capacity', 
            'wattage', 'socket', 'form_factor', 'interface', 'type',
            'clock_speed', 'memory_size', 'memory_type', 'efficiency_rating',
            'core_count', 'thread_count', 'base_clock', 'boost_clock',
            'memory_clock', 'memory_bandwidth', 'tdp', 'efficiency',
            'modular', 'sata_ports', 'm2_slots', 'pcie_slots',
            'dimms', 'speed_mhz', 'timing', 'cas_latency',
            'size', 'color', 'rpm', 'airflow', 'noise_level', 'pwm',
            'external_volume', 'internal_35_bays', 'side_panel',
            'frequency_response', 'microphone', 'wireless', 'enclosure_type',
            'price_per_gb', 'cache', 'bd', 'dvd', 'cd', 'bd_write', 'dvd_write', 'cd_write',
            'configuration', 'wattage', 'capacity_w', 'capacity_va',
            'resolutions', 'connection', 'focus_type', 'os', 'fov',
            'screen_size', 'resolution', 'refresh_rate', 'response_time', 'panel_type', 'aspect_ratio',
            'tracking_method', 'max_dpi', 'hand_orientation',
            'style', 'switches', 'backlit', 'tenkeyless', 'connection_type',
            'modules', 'first_word_latency', 'max_memory', 'memory_slots',
            'length'
        ];
        
        foreach ($specFields as $field) {
            if (isset($item[$field]) && !empty($item[$field])) {
                $specs[$field] = $item[$field];
            }
        }
        
        return !empty($specs) ? json_encode($specs) : null;
    }
    
    /**
     * Clean model name
     */
    private static function cleanModelName($name) {
        $name = trim(strip_tags($name));
        $name = preg_replace('/\s+/', ' ', $name);
        $name = html_entity_decode($name);
        
        // Remove common prefixes/suffixes
        $name = preg_replace('/^(AMD|Intel|NVIDIA|ASUS|MSI|Gigabyte|Corsair|Razer|Logitech|Samsung|Western Digital|Seagate|Cooler Master|Thermaltake|Noctua)\s+/i', '', $name);
        $name = trim($name);
        
        return $name;
    }
}

// === DB CONNECTION ===
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=28800, SESSION interactive_timeout=28800",
            ];

            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            echo "✅ DB connected successfully!\n";
        } catch (PDOException $e) {
            die("❌ DB connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    try {
        $pdo->query("SELECT 1");
    } catch (PDOException $e) {
        echo "⚠️ Connection lost, reconnecting...\n";
        $pdo = null;
        return getDbConnection();
    }
    
    return $pdo;
}

// === CONFIG ===
define('BATCH_SIZE', 100); // Larger batch size for faster import

// === Statistics Tracker ===
class ValidationStats {
    public static $stats = [
        'total_processed' => 0,
        'passed_validation' => 0,
        'failed_validation' => 0,
        'category_mismatches' => 0,
        'price_violations' => 0,
        'by_category' => [],
        'by_source' => []
    ];
    
    public static function log($message) {
        echo $message . "\n";
    }
    
    public static function printSummary() {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "📊 IMPORT STATISTICS:\n";
        echo str_repeat('=', 60) . "\n";
        echo "Total Processed:       " . self::$stats['total_processed'] . "\n";
        echo "✅ Passed Validation:  " . self::$stats['passed_validation'] . "\n";
        echo "❌ Failed Validation:  " . self::$stats['failed_validation'] . "\n";
        echo "   - Category Errors:  " . self::$stats['category_mismatches'] . "\n";
        echo "   - Price Violations: " . self::$stats['price_violations'] . "\n";
        echo "\nBy Category:\n";
        foreach (self::$stats['by_category'] as $cat => $count) {
            echo "   $cat: $count\n";
        }
        echo str_repeat('=', 60) . "\n";
    }
}

// === BATCH UPSERT (FIXED - removed source_platform) ===
function batchUpsertComponents($components) {
    if (empty($components)) return 0;
    
    $pdo = getDbConnection();
    $inserted = 0;
    $batches = array_chunk($components, BATCH_SIZE);
    
    foreach ($batches as $batchIndex => $batch) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO components (type, brand, model, price, currency, image_url, source_url, specs, last_updated)
                VALUES (:type, :brand, :model, :price, 'PHP', :image_url, :source_url, :specs, NOW())
                ON DUPLICATE KEY UPDATE 
                    price = VALUES(price),
                    image_url = VALUES(image_url),
                    source_url = VALUES(source_url),
                    specs = VALUES(specs),
                    last_updated = NOW()
            ");
            
            foreach ($batch as $data) {
                $cleanData = [
                    'type' => $data['type'],
                    'brand' => $data['brand'],
                    'model' => $data['model'],
                    'price' => $data['price'],
                    'image_url' => $data['image_url'] ?? '',
                    'source_url' => $data['source_url'] ?? '',
                    'specs' => $data['specs'] ?? null
                ];
                
                if ($stmt->execute($cleanData)) {
                    $inserted++;
                }
            }
            
            $pdo->commit();
            echo "✅ Batch " . ($batchIndex + 1) . "/" . count($batches) . " completed ($inserted inserted)\n";
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo "❌ Batch transaction failed: " . $e->getMessage() . "\n";
            // Continue with next batch
        }
    }
    
    return $inserted;
}

// === PCPARTPICKER JSON SCRAPER - DIRECT IMPORT (NO VALIDATION) ===
function importPCPartPickerJSON() {
    echo "🔍 Importing PCPartPicker JSON Dataset...\n";
    
    // Process JSON files
    $jsonProducts = PCPartPickerJSONProcessor::processJSONFiles();
    
    if (empty($jsonProducts)) {
        echo "❌ No products found to import\n";
        return [];
    }
    
    // DIRECT IMPORT - No validation for PCPartPicker data
    $validatedResults = [];
    
    foreach ($jsonProducts as $product) {
        ValidationStats::$stats['total_processed']++;
        
        $category = $product['category'];
        $brand = $product['brand'];
        
        // Map category to database ENUM type
        $dbType = PCPartPickerJSONProcessor::mapCategoryToDatabaseType($category);
        
        // DIRECT IMPORT - All PCPartPicker products are accepted
        ValidationStats::$stats['passed_validation']++;
        ValidationStats::$stats['by_category'][$category] = 
            (ValidationStats::$stats['by_category'][$category] ?? 0) + 1;
        
        $validatedResults[] = [
            'type' => $dbType, // Use mapped database type, not category
            'brand' => $brand,
            'model' => $product['model'],
            'price' => $product['price'],
            'image_url' => $product['image_url'] ?? '',
            'source_url' => $product['source_url'] ?? '',
            'specs' => $product['specs'] ?? null
        ];
        
        ValidationStats::log("  ✅ IMPORTED [$dbType] $brand {$product['model']} - ₱{$product['price']}");
    }
    
    echo "  ✅ PCPartPicker JSON: " . count($validatedResults) . " products ready for import\n";
    
    return $validatedResults;
}

// === PC EXPRESS WEB SCRAPER (WITH VALIDATION) ===
function scrapePCExpress() {
    echo "🔍 Starting PC Express scraping...\n";
    
    // Your existing PC Express scraping code here
    $pcExpressProducts = []; // This should contain your scraped data
    
    if (empty($pcExpressProducts)) {
        echo "❌ No PC Express products found to import\n";
        return [];
    }
    
    $validatedResults = [];
    
    foreach ($pcExpressProducts as $product) {
        ValidationStats::$stats['total_processed']++;
        
        $category = $product['category'] ?? CategoryValidator::detectCategory($product['model'], $product['price']);
        
        if (!$category) {
            ValidationStats::$stats['failed_validation']++;
            ValidationStats::$stats['category_mismatches']++;
            ValidationStats::log("  ❌ REJECTED [Unknown] {$product['model']} - Cannot determine category");
            continue;
        }
        
        // USE VALIDATION FOR PC EXPRESS DATA
        $validation = CategoryValidator::validate($category, $product['model'], $product['price']);
        
        if (!$validation['valid']) {
            ValidationStats::$stats['failed_validation']++;
            if (strpos($validation['reason'], 'Price') !== false) {
                ValidationStats::$stats['price_violations']++;
            } else {
                ValidationStats::$stats['category_mismatches']++;
            }
            ValidationStats::log("  ❌ REJECTED [$category] {$product['model']} - {$validation['reason']}");
            continue;
        }
        
        $brand = $validation['brand'] ?? $product['brand'] ?? CategoryValidator::extractBrand($product['model']);
        
        ValidationStats::$stats['passed_validation']++;
        ValidationStats::$stats['by_category'][$category] = 
            (ValidationStats::$stats['by_category'][$category] ?? 0) + 1;
        
        $validatedResults[] = [
            'type' => $category,
            'brand' => $brand,
            'model' => $product['model'],
            'price' => $product['price'],
            'image_url' => $product['image_url'] ?? '',
            'source_url' => $product['source_url'] ?? '',
            'specs' => $product['specs'] ?? null
        ];
        
        ValidationStats::log("  ✅ APPROVED [$category] $brand {$product['model']} - ₱{$product['price']}");
    }
    
    echo "  ✅ PC Express: " . count($validatedResults) . " validated products ready for import\n";
    
    return $validatedResults;
}

// === MAIN EXECUTION ===
echo "🚀 Starting PCPartPicker JSON Dataset Import...\n";
echo "⏰ Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);

// Initialize connection
getDbConnection();

// Import JSON data
echo "\n" . str_repeat('=', 50) . "\n";
echo "📦 IMPORTING PCPARTPICKER JSON DATA\n";
echo str_repeat('=', 50) . "\n";

$validatedData = importPCPartPickerJSON();

if (!empty($validatedData)) {
    // Save verified records to JSON file
    echo "\n💾 Saving verified records to JSON file...\n";
    $saveResult = VerifiedRecordsSaver::saveVerifiedRecords($validatedData);
    
    echo "\n💾 Inserting into database...\n";
    $inserted = batchUpsertComponents($validatedData);
    
    $endTime = microtime(true);
    $elapsed = round($endTime - $startTime, 2);
    
    // Print validation statistics
    ValidationStats::printSummary();
    
    // Final summary
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "📊 IMPORT SUMMARY:\n";
    echo str_repeat('=', 60) . "\n";
    echo "✅ Total components inserted/updated: $inserted\n";
    echo "💾 Total validated components: " . count($validatedData) . "\n";
    echo "📁 Verified records saved to: " . basename(VERIFIED_RECORDS_FILE) . "\n";
    echo "⏱️  Time taken: {$elapsed}s\n";
    echo "📝 Success rate: " . round(($inserted/ValidationStats::$stats['total_processed'])*100, 1) . "%\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "🎉 PCPartPicker JSON import completed successfully!\n";
} else {
    echo "\n❌ No data to import. Please check your JSON files.\n";
}

// Instructions for JSON files
echo "\n" . str_repeat('=', 60) . "\n";
echo "📁 JSON FILE SETUP INSTRUCTIONS:\n";
echo str_repeat('=', 60) . "\n";
echo "1. Create directory: " . PCPARTPICKER_JSON_DIR . "\n";
echo "2. Place your PCPartPicker JSON files in that directory\n";
echo "3. Supported file names (automatically detected):\n";
echo "   - cpu.json, gpu.json, motherboard.json, ram.json\n";
echo "   - storage.json, psu.json, case.json\n";
echo "   - case-accessory.json, case-fan.json, cpu-cooler.json\n";
echo "   - external-hard-drive.json, headphones.json, keyboard.json\n";
echo "   - mouse.json, optical-drive.json, speakers.json\n";
echo "   - ups.json, webcam.json, monitor.json\n";
echo "4. JSON structure should contain arrays of products with:\n";
echo "   - 'name' or 'title' or 'model' (required)\n";
echo "   - 'price' or 'usd_price' (optional, will be converted to PHP)\n";
echo "   - 'brand' or 'manufacturer' (optional, auto-detected)\n";
echo "   - 'image' or 'image_url' (optional)\n";
echo "   - 'url' or 'link' (optional)\n";
echo "5. Verified records will be saved to: " . basename(VERIFIED_RECORDS_FILE) . "\n";
echo "6. Run this script again to import the data\n";
echo str_repeat('=', 60) . "\n";
?>