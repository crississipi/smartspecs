<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ai_service.php';
header('Content-Type: application/json; charset=utf-8');

// Add CORS headers if needed
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

function handleError($message, $code = 500) {
    error_log("API Error: $message");
    sendJSON(['success' => false, 'message' => $message], $code);
}

try {
    if (!isLoggedIn()) {
        handleError('Unauthorized', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        handleError('Method not allowed. Use POST.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Accept component details directly (no DB lookup needed for online search)
    $componentType  = $data['component_type'] ?? $data['type'] ?? null;
    $componentBrand = $data['brand'] ?? null;
    $componentModel = $data['model'] ?? null;
    $componentPrice = isset($data['price']) ? (float)$data['price'] : 0;
    $component_id   = $data['component_id'] ?? null;

    // Build original component info from request data
    if (!$componentModel && !$component_id) {
        handleError('Component details (brand, model, price) or component_id is required', 400);
    }

    $originalComponent = [
        'type'  => $componentType ?? 'unknown',
        'brand' => $componentBrand ?? 'Unknown',
        'model' => $componentModel ?? 'Unknown',
        'price' => $componentPrice,
    ];

    // Use AI-powered online search for alternatives
    $location = getUserLocation();
    $alts = generateAlternativesOnline($originalComponent, $location);

    sendJSON([
        'success'            => true,
        'original_component' => $originalComponent,
        'alternatives'       => array_slice($alts, 0, 8),
        'compatibility_note' => 'Alternatives are based on similar specs and price range. Always verify compatibility before purchasing.',
    ]);

} catch (Exception $e) {
    handleError($e->getMessage());
}
?>

