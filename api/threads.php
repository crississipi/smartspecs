<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = getCurrentUserId();
$conn = getDBConnection();

// ADD THIS CHECK:
if (!$conn) {
    sendJSON(['success' => false, 'message' => 'Database connection failed'], 500);
}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getThread($conn, $userId, $_GET['id']);
        } else {
            getThreads($conn, $userId);
        }
        break;
    case 'POST':
        createThread($conn, $userId);
        break;
    case 'PUT':
        if (isset($_GET['id'])) {
            updateThread($conn, $userId, $_GET['id']);
        } else {
            sendJSON(['success' => false, 'message' => 'Thread ID required'], 400);
        }
        break;
    case 'DELETE':
        if (isset($_GET['id'])) {
            deleteThread($conn, $userId, $_GET['id']);
        } else {
            sendJSON(['success' => false, 'message' => 'Thread ID required'], 400);
        }
        break;
    default:
        sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

function getThreads($conn, $userId) {
    // ADD ERROR CHECKING:
    if (!$conn) {
        sendJSON(['success' => false, 'message' => 'Database connection failed'], 500);
    }
    
    $stmt = $conn->prepare("SELECT id, title, created_at, updated_at FROM threads WHERE user_id = ? ORDER BY updated_at DESC");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database query failed: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        sendJSON(['success' => false, 'message' => 'Failed to execute query: ' . $stmt->error], 500);
    }
    
    $result = $stmt->get_result();
    
    $threads = [];
    while ($row = $result->fetch_assoc()) {
        $threads[] = $row;
    }

    if (empty($threads)) {
        sendJSON([
            'success' => true,
            'threads' => [],
            'show_prelim_template' => true
        ]);
    }

    sendJSON([
        'success' => true,
        'threads' => $threads,
        'show_prelim_template' => false
    ]);
}

function getThread($conn, $userId, $threadId) {
    // ADD ERROR CHECKING:
    if (!$conn) {
        sendJSON(['success' => false, 'message' => 'Database connection failed'], 500);
    }
    
    // Verify thread belongs to user
    $stmt = $conn->prepare("SELECT id, title, created_at, updated_at FROM threads WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database query failed: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("ii", $threadId, $userId);
    if (!$stmt->execute()) {
        sendJSON(['success' => false, 'message' => 'Failed to execute query: ' . $stmt->error], 500);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendJSON(['success' => false, 'message' => 'Thread not found'], 404);
    }
    
    $thread = $result->fetch_assoc();
    
    // Get messages - include data_type
    $stmt = $conn->prepare("SELECT id, role, content, data_type, created_at FROM messages WHERE thread_id = ? ORDER BY created_at ASC");
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database query failed: ' . $conn->error], 500);
    }
    
    $stmt->bind_param("i", $threadId);
    if (!$stmt->execute()) {
        sendJSON(['success' => false, 'message' => 'Failed to execute query: ' . $stmt->error], 500);
    }
    
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messageData = [
            'id' => $row['id'],
            'role' => $row['role'],
            'content' => $row['content'],
            'created_at' => $row['created_at']
        ];
        
        // Parse JSON if it's a recommendation
        if (isset($row['data_type']) && $row['data_type'] === 'recommendation') {
            $messageData['data_type'] = 'recommendation';
            $messageData['data'] = json_decode($row['content'], true);
        } else {
            $messageData['data_type'] = 'text';
        }
        
        $messages[] = $messageData;
    }
    
    $thread['messages'] = $messages;
    
    // Debug logging
    error_log("Thread {$threadId} loaded with " . count($messages) . " messages for user {$userId}");
    
    sendJSON(['success' => true, 'thread' => $thread]);
}

function createThread($conn, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? 'New Conversation');
    
    $stmt = $conn->prepare("INSERT INTO threads (user_id, title) VALUES (?, ?)");
    $stmt->bind_param("is", $userId, $title);
    
    if ($stmt->execute()) {
        $threadId = $conn->insert_id;

        // Wait until a first message is saved (handled in messages.php)
        // But in case the user leaves it empty, remove it later.
        register_shutdown_function(function() use ($conn, $threadId) {
            $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE thread_id = ?");
            $check->bind_param("i", $threadId);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            if ($result['cnt'] == 0) {
                $del = $conn->prepare("DELETE FROM threads WHERE id = ?");
                $del->bind_param("i", $threadId);
                $del->execute();
            }
        });

        sendJSON([
            'success' => true,
            'message' => 'Thread created',
            'thread' => [
                'id' => $threadId,
                'title' => $title,
                'user_id' => $userId
            ]
        ]);
    } else {
        sendJSON(['success' => false, 'message' => 'Failed to create thread'], 500);
    }
}

function updateThread($conn, $userId, $threadId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? '');
    
    if (empty($title)) {
        sendJSON(['success' => false, 'message' => 'Title is required'], 400);
    }
    
    // Verify thread belongs to user
    $stmt = $conn->prepare("UPDATE threads SET title = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $title, $threadId, $userId);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJSON(['success' => true, 'message' => 'Thread updated']);
        } else {
            sendJSON(['success' => false, 'message' => 'Thread not found or unauthorized'], 404);
        }
    } else {
        sendJSON(['success' => false, 'message' => 'Failed to update thread'], 500);
    }
}

function deleteThread($conn, $userId, $threadId) {
    // Verify thread belongs to user
    $stmt = $conn->prepare("DELETE FROM threads WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $threadId, $userId);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJSON(['success' => true, 'message' => 'Thread deleted']);
        } else {
            sendJSON(['success' => false, 'message' => 'Thread not found or unauthorized'], 404);
        }
    } else {
        sendJSON(['success' => false, 'message' => 'Failed to delete thread'], 500);
    }
}
?>

