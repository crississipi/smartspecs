<?php
/**
 * API endpoint to trigger find_pcpartpicker_images_google.py
 * Can be called via cron job or web request
 * 
 * Usage:
 * - Cron: curl https://your-site.com/api/run_image_fetch.php
 * - Web: Visit the URL (will run in background)
 */

// Load config without starting session (we don't need it for this endpoint)
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Security: Only allow from localhost or with API key
$api_key = $_GET['key'] ?? $_POST['key'] ?? '';
$expected_key = getenv('IMAGE_FETCH_API_KEY') ?: 'your-secret-key-change-this';

// Allow localhost without key (for development)
$is_localhost = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost']) || 
                strpos($_SERVER['HTTP_HOST'], 'localhost') !== false;

if (!$is_localhost && $api_key !== $expected_key) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Provide valid API key via ?key=your_key'
    ]);
    exit;
}

// Check if script is already running
$lock_file = __DIR__ . '/../scripts/image_cache/.fetch_lock';
$pid_file = __DIR__ . '/../scripts/image_cache/.fetch_pid';

if (file_exists($lock_file)) {
    $lock_time = filemtime($lock_file);
    $elapsed = time() - $lock_time;
    
    // If lock is older than 2 hours, assume process died and remove lock
    if ($elapsed > 7200) {
        @unlink($lock_file);
        @unlink($pid_file);
    } else {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Image fetch script is already running',
            'started' => date('Y-m-d H:i:s', $lock_time),
            'elapsed_seconds' => $elapsed
        ]);
        exit;
    }
}

// Create lock file
$scripts_dir = __DIR__ . '/../scripts';
$cache_dir = $scripts_dir . '/image_cache';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

touch($lock_file);

// Determine Python command
$python_cmd = 'python3';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $python_cmd = 'python';
}

$script_path = $scripts_dir . '/find_pcpartpicker_images_google.py';

if (!file_exists($script_path)) {
    @unlink($lock_file);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Script not found: ' . $script_path
    ]);
    exit;
}

// Run script in background (non-blocking)
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows
    $command = "start /B $python_cmd \"$script_path\" > \"$cache_dir/fetch_output.log\" 2>&1";
    pclose(popen($command, 'r'));
} else {
    // Linux/Mac/Unix
    $command = "cd \"$scripts_dir\" && nohup $python_cmd find_pcpartpicker_images_google.py > image_cache/fetch_output.log 2>&1 & echo $!";
    $pid = shell_exec($command);
    
    // Save PID
    if ($pid) {
        file_put_contents($pid_file, trim($pid));
    }
}

// Return success immediately (script runs in background)
echo json_encode([
    'success' => true,
    'message' => 'Image fetch script started in background',
    'pid' => isset($pid) ? trim($pid) : null,
    'log_file' => $cache_dir . '/fetch_output.log'
]);

