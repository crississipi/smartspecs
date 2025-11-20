<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

// Get request_id from query parameter
$requestId = $_GET['request_id'] ?? null;

if (!$requestId) {
    sendJSON(['success' => false, 'message' => 'Request ID is required'], 400);
}

// Call Python AI service progress endpoint
$aiServiceUrl = getenv('PYTHON_SERVICE_URL') ?: getenv('AI_SERVICE_URL') ?: 'http://localhost:5000';

$ch = curl_init($aiServiceUrl . '/progress/' . urlencode($requestId));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    if ($data) {
        sendJSON($data);
    } else {
        sendJSON(['success' => false, 'message' => 'Invalid response from Python service'], 500);
    }
} else {
    sendJSON(['success' => false, 'message' => 'Progress not found'], 404);
}
?>

