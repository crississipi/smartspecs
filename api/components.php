<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

/**
 * SmartSpecs - Components API
 * Supports listing, searching, and fetching images for PC components
 */

/**
 * Connect to AivenDB (MySQL)
 */
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_errno) {
        sendJSON(['success' => false, 'message' => 'Database connection failed: ' . $db->connect_error], 500);
    }
    $db->set_charset("utf8mb4");
} catch (Exception $e) {
    sendJSON(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}

/**
 * Automatically update components weekly or on first access of the week
 */
function maybeUpdateComponents($db) {
    // Check last update timestamp from components table
    $result = $db->query("SELECT MAX(last_updated) AS last_update FROM components");
    $row = $result->fetch_assoc();
    $lastUpdate = $row && $row['last_update'] ? strtotime($row['last_update']) : 0;

    $now = time();
    $currentWeekStart = strtotime("monday this week", $now);

    // Run update if last update was before the start of this week
    if ($lastUpdate < $currentWeekStart) {
        $updateScript = __DIR__ . '/update_components.php';

        // Run asynchronously so page load is not blocked
        if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
            // For Windows servers
            pclose(popen("start /B php " . escapeshellarg($updateScript), "r"));
        } else {
            // For Linux/macOS servers
            shell_exec("php " . escapeshellarg($updateScript) . " > /dev/null 2>&1 &");
        }

        // Log for debugging
        file_put_contents(__DIR__ . '/update_log.txt', "[" . date('Y-m-d H:i:s') . "] Auto update triggered.\n", FILE_APPEND);
    }
}

// Trigger auto-update before serving any data
maybeUpdateComponents($db);

/**
 * Fetch component image
 */
function getComponentImage($db, $componentName, $componentType) {
    $stmt = $db->prepare("SELECT image_url FROM components WHERE model LIKE ? AND type = ? LIMIT 1");
    $likeName = '%' . $componentName . '%';
    $stmt->bind_param("ss", $likeName, $componentType);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row && !empty($row['image_url'])) {
        return $row['image_url'];
    }
    return 'https://via.placeholder.com/400x300?text=' . urlencode(strtoupper($componentType));
}

/**
 * Search components by keyword
 */
function searchComponents($db, $query) {
    $like = '%' . $query . '%';
    $stmt = $db->prepare("SELECT * FROM components WHERE model LIKE ? OR brand LIKE ? ORDER BY price ASC");
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Handle API requests
 */
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $type = $_GET['type'] ?? null;
        $brand = $_GET['brand'] ?? null;
        $minPrice = $_GET['min_price'] ?? null;
        $maxPrice = $_GET['max_price'] ?? null;
        $name = $_GET['name'] ?? null;

        // Return a specific component image
        if ($name && $type) {
            $imageUrl = getComponentImage($db, $name, $type);
            sendJSON(['success' => true, 'image_url' => $imageUrl]);
        }

        // Build query dynamically
        $query = "SELECT * FROM components WHERE 1=1";
        $params = [];
        $types = '';

        if ($type) {
            $query .= " AND type = ?";
            $params[] = $type;
            $types .= 's';
        }
        if ($brand) {
            $query .= " AND brand = ?";
            $params[] = $brand;
            $types .= 's';
        }
        if ($minPrice && $maxPrice) {
            $query .= " AND price BETWEEN ? AND ?";
            $params[] = $minPrice;
            $params[] = $maxPrice;
            $types .= 'dd';
        }

        $query .= " ORDER BY price ASC";
        $stmt = $db->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $components = $result->fetch_all(MYSQLI_ASSOC);

        sendJSON(['success' => true, 'count' => count($components), 'components' => $components]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $query = $data['query'] ?? '';

        if (empty($query)) {
            sendJSON(['success' => false, 'message' => 'Search query required'], 400);
        }

        $results = searchComponents($db, $query);
        sendJSON(['success' => true, 'count' => count($results), 'results' => $results]);
        break;

    default:
        sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}
?>
