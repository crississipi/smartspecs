<?php
// Start output buffering at the VERY beginning
if (ob_get_level() == 0) {
    ob_start();
}

require_once __DIR__ . '/../config.php';

// Don't start session again - it's already started in config.php
// Just verify it's active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    // Clean output buffer before sending JSON
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set JSON header
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($method) {
        case 'POST':
            handlePost($action);
            break;
        case 'GET':
            handleGet($action);
            break;
        default:
            sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log('Auth API Error: ' . $e->getMessage());
    
    // Ensure clean output for errors too
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}

function handlePost($action) {
    $conn = getDBConnection();
    
    // Only parse JSON for actions that need it
    $needsJson = in_array($action, ['register', 'login', 'forgot-password', 'verify-otp', 'reset-password', 'google-auth']);
    
    if ($needsJson) {
        $input = file_get_contents('php://input');
        error_log("Raw input for $action: " . substr($input, 0, 200));
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON parse error: " . json_last_error_msg());
            sendJSON(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()], 400);
        }
    } else {
        // For actions that don't need JSON, use empty array
        $data = [];
    }
    
    switch ($action) {
        case 'register':
            register($conn, $data);
            break;
        case 'login':
            login($conn, $data);
            break;
        case 'logout':
            logout();
            break;
        case 'forgot-password':
            forgotPassword($conn, $data);
            break;
        case 'verify-otp':
            verifyOTP($conn, $data);
            break;
        case 'reset-password':
            resetPassword($conn, $data);
            break;
        case 'google-auth':
            handleGoogleAuth($conn, $data);
            break;
        case 'debug-logout':
            error_log("DEBUG logout: Session ID: " . session_id());
            error_log("DEBUG logout: User ID in session: " . ($_SESSION['user_id'] ?? 'not_set'));
            sendJSON(['success' => true, 'debug' => 'Test JSON response']);
            break;
        case 'test-simple':
            error_log("Test-simple endpoint called");
            sendJSON([
                'success' => true, 
                'message' => 'Simple test works',
                'session_id' => session_id(),
                'user_id' => $_SESSION['user_id'] ?? 'not_set'
            ]);
            break;
        default:
            sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleGet($action) {
    switch ($action) {
        case 'check':
            checkAuth();
            break;
        default:
            sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function register($conn, $data) {
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $name = $data['name'] ?? explode('@', $email)[0];
    
    if (empty($email) || empty($password)) {
        sendJSON(['success' => false, 'message' => 'Email and password are required'], 400);
    }
    
    if (!isValidEmail($email)) {
        sendJSON(['success' => false, 'message' => 'Invalid email format'], 400);
    }
    
    if (strlen($password) < 8) {
        sendJSON(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
    }
    
    // Check if user already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        sendJSON(['success' => false, 'message' => 'Email already registered'], 409);
    }
    
    // Create new user
    $hashedPassword = hashPassword($password);
    $stmt = $conn->prepare("INSERT INTO users (email, password, name) VALUES (?, ?, ?)");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("sss", $email, $hashedPassword, $name);
    
    if ($stmt->execute()) {
        $userId = $conn->insert_id;
        
        // Initialize user preferences
        $stmt = $conn->prepare("INSERT INTO user_preferences (user_id) VALUES (?)");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        
        sendJSON([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $userId,
                'email' => $email,
                'name' => $name
            ]
        ]);
    } else {
        sendJSON(['success' => false, 'message' => 'Registration failed: ' . $stmt->error], 500);
    }
}

function login($conn, $data) {
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        sendJSON(['success' => false, 'message' => 'Email and password are required'], 400);
    }
    
    $stmt = $conn->prepare("SELECT id, email, name, password FROM users WHERE email = ?");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database error: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendJSON(['success' => false, 'message' => 'Invalid email or password'], 401);
    }
    
    $user = $result->fetch_assoc();
    
    if (!verifyPassword($password, $user['password'])) {
        sendJSON(['success' => false, 'message' => 'Invalid email or password'], 401);
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    
    sendJSON([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name']
        ]
    ]);
}

function logout() {
    error_log("Logout started for user: " . ($_SESSION['user_id'] ?? 'not_set'));
    
    // Store session data for logging before clearing
    $userId = $_SESSION['user_id'] ?? null;
    $userEmail = $_SESSION['user_email'] ?? null;
    
    // Clear session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    $sessionDestroyed = session_destroy();
    
    error_log("Logout completed - User: $userEmail, Session destroyed: " . ($sessionDestroyed ? 'yes' : 'no'));
    
    // Send clean JSON response
    sendJSON([
        'success' => true, 
        'message' => 'Logged out successfully',
        'debug' => [
            'user_id' => $userId,
            'session_destroyed' => $sessionDestroyed
        ]
    ]);
}

function checkAuth() {
    if (isLoggedIn()) {
        $userInfo = getCurrentUserInfo();
        sendJSON([
            'success' => true,
            'authenticated' => true,
            'user' => $userInfo
        ]);
    } else {
        sendJSON(['success' => true, 'authenticated' => false]);
    }
}

function forgotPassword($conn, $data) {
    $email = trim($data['email'] ?? '');
    
    if (empty($email) || !isValidEmail($email)) {
        sendJSON(['success' => false, 'message' => 'Valid email is required'], 400);
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Don't reveal if email exists for security
        sendJSON(['success' => true, 'message' => 'If the email exists, an OTP has been sent']);
    }
    
    // Generate OTP
    $otp = generateOTP(5);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // Delete old OTPs
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    
    // Store new OTP
    $stmt = $conn->prepare("INSERT INTO password_resets (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $otp, $expiresAt);
    $stmt->execute();
    
    // Log OTP for development (remove in production!)
    error_log("OTP for $email: $otp");
    
    sendJSON([
        'success' => true,
        'message' => 'OTP sent to email',
        'otp' => $otp // Remove this in production!
    ]);
}

function verifyOTP($conn, $data) {
    $email = trim($data['email'] ?? '');
    $otp = trim($data['otp'] ?? '');
    
    if (empty($email) || empty($otp)) {
        sendJSON(['success' => false, 'message' => 'Email and OTP are required'], 400);
    }
    
    $stmt = $conn->prepare("SELECT id FROM password_resets WHERE email = ? AND otp = ? AND expires_at > NOW()");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendJSON(['success' => false, 'message' => 'Invalid or expired OTP'], 400);
    }
    
    // Store email in session for password reset
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_otp'] = $otp;
    
    sendJSON(['success' => true, 'message' => 'OTP verified']);
}

function resetPassword($conn, $data) {
    $email = $_SESSION['reset_email'] ?? trim($data['email'] ?? '');
    $newPassword = $data['password'] ?? '';
    
    if (empty($email) || empty($newPassword)) {
        sendJSON(['success' => false, 'message' => 'Email and new password are required'], 400);
    }
    
    if (strlen($newPassword) < 8) {
        sendJSON(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
    }
    
    // Verify OTP was used
    $otp = $_SESSION['reset_otp'] ?? '';
    if (!empty($otp)) {
        $stmt = $conn->prepare("SELECT id FROM password_resets WHERE email = ? AND otp = ? AND expires_at > NOW()");
        $stmt->bind_param("ss", $email, $otp);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendJSON(['success' => false, 'message' => 'OTP verification required'], 400);
        }
    }
    
    // Update password
    $hashedPassword = hashPassword($newPassword);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashedPassword, $email);
    
    if ($stmt->execute()) {
        // Delete used OTP
        if (!empty($otp)) {
            $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ? AND otp = ?");
            $stmt->bind_param("ss", $email, $otp);
            $stmt->execute();
        }
        
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_otp']);
        
        sendJSON(['success' => true, 'message' => 'Password reset successful']);
    } else {
        sendJSON(['success' => false, 'message' => 'Password reset failed'], 500);
    }
}

function handleGoogleAuth($conn, $data) {
    $email = null;
    $name = '';
    $googleId = null;
    
    // Method 1: JWT credential from Google One Tap
    if (isset($data['credential'])) {
        try {
            $credential = $data['credential'];
            $parts = explode('.', $credential);
            
            if (count($parts) === 3) {
                // Decode the payload (base64url)
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                
                if ($payload && isset($payload['email'])) {
                    $email = $payload['email'];
                    $name = $payload['name'] ?? explode('@', $email)[0];
                    $googleId = $payload['sub'] ?? null;
                }
            }
        } catch (Exception $e) {
            error_log('Error decoding Google credential: ' . $e->getMessage());
        }
    }
    
    // Method 2: Direct user info from OAuth token
    if (empty($email) && isset($data['email'])) {
        $email = trim($data['email']);
        $name = $data['name'] ?? explode('@', $email)[0];
        $googleId = $data['google_id'] ?? null;
    }
    
    if (empty($email)) {
        sendJSON(['success' => false, 'message' => 'Invalid Google authentication data'], 400);
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, email, name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Create new user (Google users don't need a password)
        $placeholderPassword = hashPassword(bin2hex(random_bytes(16)));
        $stmt = $conn->prepare("INSERT INTO users (email, name, password, google_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $email, $name, $placeholderPassword, $googleId);
        
        if ($stmt->execute()) {
            $userId = $conn->insert_id;
            
            // Initialize user preferences
            $stmt = $conn->prepare("INSERT INTO user_preferences (user_id) VALUES (?)");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
        } else {
            sendJSON(['success' => false, 'message' => 'Failed to create user: ' . $stmt->error], 500);
        }
    } else {
        $user = $result->fetch_assoc();
        $userId = $user['id'];
        $name = $user['name'];
        
        // Update Google ID if not set
        if (!empty($googleId)) {
            $stmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ? AND (google_id IS NULL OR google_id = '')");
            $stmt->bind_param("si", $googleId, $userId);
            $stmt->execute();
        }
    }
    
    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;
    
    sendJSON([
        'success' => true,
        'message' => 'Google authentication successful',
        'user' => [
            'id' => $userId,
            'email' => $email,
            'name' => $name
        ]
    ]);
}
?>