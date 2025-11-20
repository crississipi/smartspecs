<?php
require_once __DIR__ . '/../config.php';
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
    $component_id = $data['component_id'] ?? null;

    if (!$component_id) {
        handleError('Component ID is required', 400);
    }

    $aiServiceUrl = getenv('PYTHON_SERVICE_URL') ?: getenv('AI_SERVICE_URL') ?: 'http://localhost:5000';
    $url = $aiServiceUrl . '/alternatives';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['component_id' => (int)$component_id]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode(['component_id' => (int)$component_id]))
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = @curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $responseData = json_decode($response, true);
        if ($responseData) {
            echo json_encode($responseData);
        } else {
            error_log("Failed to decode response from Python service. Response: " . substr($response ?? '', 0, 500));
            handleError('Invalid response from service', 500);
        }
    } else {
        error_log("Failed to fetch alternatives from Python service. HTTP Code: $httpCode, CURL Error: $curlError, Response: " . substr($response ?? '', 0, 500));
        sendJSON([
            'success' => false,
            'message' => 'Failed to fetch alternatives',
            'details' => $curlError ?: 'HTTP Error ' . $httpCode
        ], $httpCode ?: 500);
    }

} catch (Exception $e) {
    handleError($e->getMessage());
}
?>

