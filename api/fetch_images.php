<?php
/**
 * API endpoint to run image fetching script and check status
 */

header('Content-Type: application/json; charset=utf-8');

// Get the script directory
$scriptDir = __DIR__ . '/../scripts';
$pythonScript = $scriptDir . '/find_pcpartpicker_images_google.py';

// Function to get status
function getImageFetchStatus() {
    global $pythonScript;
    
    $statusFile = __DIR__ . '/../scripts/image_cache/progress.json';
    $queryLogFile = __DIR__ . '/../scripts/image_cache/query_log.json';
    
    $status = [
        'queries_used' => 0,
        'queries_limit' => 100,
        'queries_remaining' => 100,
        'can_run' => true,
        'progress' => null
    ];
    
    // Read query log
    if (file_exists($queryLogFile)) {
        $logData = json_decode(file_get_contents($queryLogFile), true);
        $today = date('Y-m-d');
        
        // Python script saves as {'date': '2024-01-01', 'count': 50}
        // Check if it's the old format (with 'date' and 'count' keys)
        if (isset($logData['date']) && isset($logData['count'])) {
            // Check if date matches today - if not, reset to 0 (new day)
            if ($logData['date'] === $today) {
                $status['queries_used'] = (int)($logData['count'] ?? 0);
            } else {
                // Different day, reset to 0 (queries reset daily)
                $status['queries_used'] = 0;
                // Update the log file to today's date with 0 count
                file_put_contents($queryLogFile, json_encode(['date' => $today, 'count' => 0], JSON_PRETTY_PRINT));
            }
        } else {
            // New format - date as key
            $status['queries_used'] = (int)($logData[$today] ?? 0);
        }
        
        $status['queries_remaining'] = max(0, $status['queries_limit'] - $status['queries_used']);
        $status['can_run'] = $status['queries_remaining'] > 0;
    }
    
    // Read progress
    if (file_exists($statusFile)) {
        $status['progress'] = json_decode(file_get_contents($statusFile), true);
    }
    
    return $status;
}

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'status';

if ($method === 'GET' && $action === 'status') {
    // Return status
    echo json_encode([
        'success' => true,
        'data' => getImageFetchStatus()
    ]);
    exit;
}

if ($method === 'POST' && $action === 'run') {
    // Check if we can run
    $status = getImageFetchStatus();
    
    if (!$status['can_run']) {
        echo json_encode([
            'success' => false,
            'message' => 'Daily query limit reached. Please try again tomorrow.',
            'data' => $status
        ]);
        exit;
    }
    
    // Run the Python script asynchronously
    $pythonPath = 'python'; // Adjust if needed (e.g., 'python3' or full path)
    $command = escapeshellarg($pythonPath) . ' ' . escapeshellarg($pythonScript);
    
    // Run in background (non-blocking)
    if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
        // Windows
        pclose(popen("start /B " . $command . " > NUL 2>&1", "r"));
    } else {
        // Linux/macOS
        shell_exec($command . " > /dev/null 2>&1 &");
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Image fetching script started',
        'data' => getImageFetchStatus()
    ]);
    exit;
}

if ($method === 'POST' && $action === 'reset') {
    // Reset query count for today (admin function)
    $queryLogFile = __DIR__ . '/../scripts/image_cache/query_log.json';
    $today = date('Y-m-d');
    
    file_put_contents($queryLogFile, json_encode(['date' => $today, 'count' => 0], JSON_PRETTY_PRINT));
    
    echo json_encode([
        'success' => true,
        'message' => 'Query count reset for today',
        'data' => getImageFetchStatus()
    ]);
    exit;
}

// Invalid request
echo json_encode([
    'success' => false,
    'message' => 'Invalid request'
]);
exit;

