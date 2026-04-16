<?php
// CRITICAL: No output before this!
error_reporting(E_ALL);
ini_set('display_errors', 0); // NEVER show errors to client
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

// Start output buffering to catch any accidental output
ob_start();

// Session configuration - MUST be set BEFORE session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

// Detect HTTPS properly for Render (behind proxy/load balancer)
$isHttps = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $isHttps = true;
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isHttps = true;
} elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    $isHttps = true;
} elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
    $isHttps = true;
}

ini_set('session.cookie_secure', $isHttps ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax'); // Changed from 'Strict' to 'Lax' for better compatibility with page refreshes
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 0); // Session cookie (expires when browser closes)
ini_set('session.gc_maxlifetime', 86400); // 24 hours - increased from 30 minutes for better persistence

// Load environment variables from .env file (if exists) for local development
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        // Use createUnsafeImmutable so values are available via getenv() too
        $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

function getConfigValue($key, $default = null) {
    static $dotenvValues = null;

    if ($dotenvValues === null) {
        $dotenvValues = [];
        $envFile = __DIR__ . '/.env';

        if (is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                    continue;
                }

                $separatorPosition = strpos($trimmedLine, '=');
                if ($separatorPosition === false) {
                    continue;
                }

                $name = trim(substr($trimmedLine, 0, $separatorPosition));
                $value = trim(substr($trimmedLine, $separatorPosition + 1));

                if ($value !== '' && strlen($value) >= 2) {
                    $quote = $value[0];
                    if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                        $value = substr($value, 1, -1);
                    }
                }

                $dotenvValues[$name] = stripcslashes($value);
            }
        }
    }

    $value = getenv($key);
    if ($value !== false && $value !== null && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    if (isset($dotenvValues[$key]) && $dotenvValues[$key] !== '') {
        return $dotenvValues[$key];
    }

    return $default;
}

// Set explicit session cookie parameters for better reliability
// This ensures cookies work properly on Render and other hosting platforms
session_set_cookie_params([
    'lifetime' => 0, // Session cookie
    'path' => '/',
    'domain' => '', // Empty = current domain only
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session AFTER configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Regenerate session ID periodically
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Database configuration - Use environment variables (no hardcoded credentials)
define('DB_HOST', getConfigValue('DB_HOST', 'localhost'));
define('DB_PORT', (int)getConfigValue('DB_PORT', 3306));
define('DB_USER', getConfigValue('DB_USER', 'root'));
define('DB_PASS', getConfigValue('DB_PASS', ''));
define('DB_NAME', getConfigValue('DB_NAME', 'defaultdb'));
define('DB_SSL', in_array(strtolower((string)getConfigValue('DB_SSL', 'false')), ['1', 'true', 'yes', 'on'], true));

// Determine allowed origin dynamically (to allow cookies)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://localhost:5173',
    'http://localhost/portfolio',
    'http://localhost/portfolio/ai-chatbot',
];

// Add Render domain if set
$render_url = getenv('RENDER_EXTERNAL_URL');
if ($render_url) {
    $allowed_origins[] = $render_url;
    $allowed_origins[] = str_replace('https://', 'http://', $render_url);
}

// Add Replit domain if set
$repl_slug = getenv('REPL_SLUG');
$repl_owner = getenv('REPL_OWNER');
if ($repl_slug && $repl_owner) {
    $replit_url = "https://{$repl_slug}.{$repl_owner}.repl.co";
    $allowed_origins[] = $replit_url;
    $allowed_origins[] = str_replace('https://', 'http://', $replit_url);
}

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function createDBConnection() {
    $conn = mysqli_init();

    if (!$conn) {
        throw new Exception('mysqli_init failed');
    }

    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);
    }

    $flags = 0;

    if (DB_SSL) {
        mysqli_ssl_set($conn, null, null, null, null, null);

        if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
            mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
        }

        $flags |= MYSQLI_CLIENT_SSL;
    }

    $connected = @mysqli_real_connect(
        $conn,
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME,
        DB_PORT,
        null,
        $flags
    );

    if (!$connected) {
        throw new Exception('Connection failed: ' . mysqli_connect_error());
    }

    if (!$conn->set_charset('utf8mb4')) {
        throw new Exception('Error setting charset: ' . $conn->error);
    }

    return $conn;
}

function isDBConnectionAlive($conn) {
    if (!($conn instanceof mysqli)) {
        return false;
    }

    try {
        return @$conn->query('SELECT 1') !== false;
    } catch (Throwable $e) {
        return false;
    }
}

// Database connection with error handling
function getDBConnection() {
    static $conn = null;

    if ($conn !== null && isDBConnectionAlive($conn)) {
        return $conn;
    }

    if ($conn !== null) {
        try {
            @$conn->close();
        } catch (Throwable $e) {
            // Ignore cleanup failures and attempt a fresh connection.
        }

        $conn = null;
    }

    try {
        $conn = createDBConnection();
        return $conn;
    } catch (Exception $e) {
        error_log('Database connection error: ' . $e->getMessage());
        // Don't expose database details to client
        return null;
    }
}

// Helper function to send JSON response
function sendJSON($data, $statusCode = 200) {
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Helper function to validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Helper function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Helper function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Helper function to generate OTP
function generateOTP($length = 5) {
    return str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Helper function to get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserInfo() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $conn = getDBConnection();
    if (!$conn) return null;
    
    $userId = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT id, email, name, created_at FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("getCurrentUserInfo prepare failed: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    $user = $result->fetch_assoc();
    
    // Get preferences
    $stmt = $conn->prepare("SELECT night_mode FROM user_preferences WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $prefsResult = $stmt->get_result();
        $preferences = $prefsResult->fetch_assoc();
        $user['preferences'] = $preferences ?: ['night_mode' => 0];
    } else {
        $user['preferences'] = ['night_mode' => 0];
    }
    
    return $user;
}

// Helper function to get user threads
function get_user_threads($user_id) {
    $conn = getDBConnection();
    if (!$conn) return [];
    
    $stmt = $conn->prepare("SELECT id, title, created_at, updated_at FROM threads WHERE user_id = ? ORDER BY updated_at DESC");
    if (!$stmt) {
        error_log("get_user_threads prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $threads = [];
    while ($row = $result->fetch_assoc()) {
        $threads[] = $row;
    }
    
    return $threads;
}

// Helper function to get base URL
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF']);
    return $protocol . "://" . $host . rtrim($path, '/');
}

// Helper function to get thread with messages
function getThreadWithMessages($threadId, $userId) {
    $conn = getDBConnection();
    if (!$conn) return null;
    
    // Verify thread belongs to user and get thread info
    $stmt = $conn->prepare("SELECT id, title, created_at, updated_at FROM threads WHERE id = ? AND user_id = ?");
    if (!$stmt) return null;
    
    $stmt->bind_param("ii", $threadId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    $thread = $result->fetch_assoc();
    
    // Get messages
    $stmt = $conn->prepare("SELECT id, role, content, created_at FROM messages WHERE thread_id = ? ORDER BY created_at ASC");
    if (!$stmt) return $thread;
    
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $thread['messages'] = $messages;
    
    return $thread;
}

// Other helper functions...
function verifyThreadOwnership($threadId, $userId) {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    $stmt = $conn->prepare("SELECT id FROM threads WHERE id = ? AND user_id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("ii", $threadId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

function saveMessage($threadId, $role, $content) {
    $conn = getDBConnection();
    if (!$conn) return null;
    
    $stmt = $conn->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, ?, ?)");
    if (!$stmt) return null;
    
    $stmt->bind_param("iss", $threadId, $role, $content);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    
    return null;
}

function updateThreadTimestamp($threadId) {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    $stmt = $conn->prepare("UPDATE threads SET updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $threadId);
    return $stmt->execute();
}

function updateThreadTitle($threadId, $title) {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    $stmt = $conn->prepare("UPDATE threads SET title = ? WHERE id = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("si", $title, $threadId);
    return $stmt->execute();
}
?>