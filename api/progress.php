<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

// Progress tracking is handled synchronously in the PHP AI service.
// This endpoint now returns a simple status since processing happens in a single request.

$requestId = $_GET['request_id'] ?? null;

if (!$requestId) {
    sendJSON(['success' => false, 'message' => 'Request ID is required'], 400);
}

// Since PHP processes synchronously, progress is either "processing" or "done".
// The frontend will receive the full response when the POST to messages.php completes.
sendJSON([
    'success' => true,
    'progress' => [
        'current_phase' => 'Processing your request...',
        'phases' => [],
    ]
]);
