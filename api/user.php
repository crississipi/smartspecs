<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = getCurrentUserId();
$conn = getDBConnection();

switch ($method) {
    case 'GET':
        getUserInfo($conn, $userId);
        break;
    case 'PUT':
        updatePreferences($conn, $userId);
        break;
    default:
        sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

function getUserInfo($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, email, name, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendJSON(['success' => false, 'message' => 'User not found'], 404);
    }
    
    $user = $result->fetch_assoc();
    
    // Get preferences
    $stmt = $conn->prepare("SELECT night_mode FROM user_preferences WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $prefsResult = $stmt->get_result();
    $preferences = $prefsResult->fetch_assoc();
    
    $user['preferences'] = $preferences ?: ['night_mode' => 0];
    
    sendJSON(['success' => true, 'user' => $user]);
}

function updatePreferences($conn, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $nightMode = isset($data['night_mode']) ? (int)$data['night_mode'] : 0;
    
    // Insert or update preferences
    $stmt = $conn->prepare("INSERT INTO user_preferences (user_id, night_mode) VALUES (?, ?) ON DUPLICATE KEY UPDATE night_mode = ?");
    $stmt->bind_param("iii", $userId, $nightMode, $nightMode);
    
    if ($stmt->execute()) {
        sendJSON(['success' => true, 'message' => 'Preferences updated', 'night_mode' => $nightMode]);
    } else {
        sendJSON(['success' => false, 'message' => 'Failed to update preferences'], 500);
    }
}
?>

