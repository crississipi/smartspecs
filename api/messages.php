<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ai_service.php';
header('Content-Type: application/json; charset=utf-8');

// Add CORS headers if needed
header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN'] ?? '*');
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

    $method = $_SERVER['REQUEST_METHOD'];
    $userId = getCurrentUserId();
    $conn = getDBConnection();

    if (!$conn) {
        handleError('Database connection failed');
    }

    switch ($method) {
        case 'POST':
            sendMessage($conn, $userId);
            break;
        default:
            handleError('Method not allowed', 405);
    }
} catch (Exception $e) {
    handleError('Server error: ' . $e->getMessage());
}

function sendMessage($conn, $userId) {
    // Get raw input first for debugging
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        handleError('Invalid JSON: ' . json_last_error_msg(), 400);
    }
    
    $threadId = $data['thread_id'] ?? null;
    $message = trim($data['message'] ?? '');
    
    error_log("Processing message - User: $userId, Thread: " . ($threadId ?? 'NULL') . ", Message: $message");
    
    if (empty($message)) {
        handleError('Message is required', 400);
    }
    
    $isNewThread = empty($threadId);
    
    // If no thread_id, create a new thread with AI-generated title
    if ($isNewThread) {
        // Get AI-generated title
        $title = generateThreadTitle($message);
        $stmt = $conn->prepare("INSERT INTO threads (user_id, title) VALUES (?, ?)");
        $stmt->bind_param("is", $userId, $title);
        $stmt->execute();
        $threadId = $conn->insert_id;
        error_log("Debug: Created new thread ID: $threadId");
    } else {
        // Verify thread belongs to user
        $stmt = $conn->prepare("SELECT id FROM threads WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $threadId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        error_log("Debug: Thread verification - Found rows: " . $result->num_rows);
        
        if ($result->num_rows === 0) {
            error_log("Debug: Thread $threadId not found for user $userId");
            sendJSON(['success' => false, 'message' => 'Thread not found or unauthorized'], 404);
        }
    }
    
    // Save user message
    $stmt = $conn->prepare("INSERT INTO messages (thread_id, role, content) VALUES (?, 'user', ?)");
    $stmt->bind_param("is", $threadId, $message);
    $stmt->execute();
    $userMessageId = $conn->insert_id;
    
    // Update thread timestamp
    $stmt = $conn->prepare("UPDATE threads SET updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    
    // Generate AI response using PHP AI service (OpenRouter)
    $history = [];
    $conn2 = getDBConnection();
    if ($conn2 && $threadId) {
        $stmtH = $conn2->prepare("SELECT role, content FROM messages WHERE thread_id = ? ORDER BY created_at ASC LIMIT 20");
        if ($stmtH) {
            $stmtH->bind_param('i', $threadId);
            $stmtH->execute();
            $resultH = $stmtH->get_result();
            while ($rowH = $resultH->fetch_assoc()) {
                $history[] = ['role' => $rowH['role'], 'content' => $rowH['content']];
            }
            $stmtH->close();
        }
    }
    $aiResponseData = processAIMessage($message, $threadId, $history);
    $aiResponse = json_encode($aiResponseData);

    error_log("=== DEBUG: Raw AI Response ===");
    error_log("Type: " . gettype($aiResponse));
    error_log("Length: " . strlen($aiResponse));
    error_log("First 500 chars: " . substr($aiResponse, 0, 500));

    // Parse the JSON response
    $responseData = json_decode($aiResponse, true);
    $jsonError = json_last_error();
    error_log("=== DEBUG: JSON Parse Result ===");
    error_log("JSON Error: " . json_last_error_msg() . " (Code: $jsonError)");
    error_log("Response Data Keys: " . (is_array($responseData) ? implode(', ', array_keys($responseData)) : 'NOT ARRAY'));
    if (is_array($responseData)) {
        error_log("Has 'success': " . (isset($responseData['success']) ? 'YES' : 'NO'));
        error_log("Success value: " . ($responseData['success'] ?? 'NOT SET'));
        error_log("Has 'data': " . (isset($responseData['data']) ? 'YES' : 'NO'));
        if (isset($responseData['data'])) {
            error_log("Data keys: " . (is_array($responseData['data']) ? implode(', ', array_keys($responseData['data'])) : 'NOT ARRAY'));
            error_log("Data type: " . ($responseData['data']['type'] ?? 'NOT SET'));
            error_log("Has ai_message: " . (isset($responseData['data']['ai_message']) ? 'YES' : 'NO'));
        }
    }

    $content = '';
    $dataType = 'text';
    $recommendationId = null;

    if ($responseData && isset($responseData['success']) && $responseData['success']) {
        error_log("=== DEBUG: Processing successful response ===");
        if (isset($responseData['data']) && isset($responseData['data']['type']) && 
            ($responseData['data']['type'] === 'recommendation' || $responseData['data']['type'] === 'upgrade_suggestion')) {
            error_log("DEBUG: Detected recommendation type");
            
            // Extract plain text introduction from ai_message
            $aiMessage = $responseData['data']['ai_message'] ?? '';
            
            // Remove HTML tags
            $aiMessage = strip_tags($aiMessage);
            
            // Extract only the introduction text (before any table/component mentions)
            $stopPhrases = ['Understanding Your Request', 'Recommended Components', 'Build Options', '.smart-recommendation'];
            $introductionText = $aiMessage;
            foreach ($stopPhrases as $phrase) {
                $pos = stripos($introductionText, $phrase);
                if ($pos !== false) {
                    $introductionText = substr($introductionText, 0, $pos);
                    break;
                }
            }
            
            // Clean up whitespace
            $introductionText = preg_replace('/\s+/', ' ', trim($introductionText));
            
            // Update ai_message to only contain the introduction
            $responseData['data']['ai_message'] = $introductionText;
            
            // Store the full recommendation/upgrade data as JSON (components are already separate in the structure)
            $content = json_encode($responseData['data']);
            $dataType = $responseData['data']['type']; // 'recommendation' or 'upgrade_suggestion'
            $recommendationId = $responseData['recommendation_id'] ?? null;
            error_log("DEBUG: Stored recommendation - Introduction: " . substr($introductionText, 0, 100) . "...");
        } else {
            error_log("DEBUG: Not a recommendation, treating as text");
            // Text response - extract plain text if HTML
            $textContent = $responseData['data']['ai_message'] ?? $responseData['response'] ?? 'Response received';
            if (strip_tags($textContent) !== $textContent) {
                $textContent = strip_tags($textContent);
            }
            $content = $textContent;
            $dataType = 'text';
        }
    } else {
        error_log("=== DEBUG: Response not successful or missing ===");
        error_log("ResponseData is array: " . (is_array($responseData) ? 'YES' : 'NO'));
        if (is_array($responseData)) {
            error_log("Success key exists: " . (isset($responseData['success']) ? 'YES' : 'NO'));
            error_log("Success value: " . ($responseData['success'] ?? 'NOT SET'));
        }
        // Fallback or error response
        $content = $responseData['data']['ai_message'] ?? $aiResponse;
        $dataType = 'text';
        error_log("DEBUG: Using fallback - Content: " . substr($content, 0, 200));
    }

    error_log("=== DEBUG: Final Values ===");
    error_log("Content type: $dataType");
    error_log("Content length: " . strlen($content));
    error_log("Recommendation ID: " . ($recommendationId ?? 'NULL'));
    
    // Save AI response with proper data_type and recommendation_id
    $stmt = $conn->prepare("INSERT INTO messages (thread_id, role, content, data_type, recommendation_id) VALUES (?, 'assistant', ?, ?, ?)");
    $stmt->bind_param("issi", $threadId, $content, $dataType, $recommendationId);
    $stmt->execute();
    $aiMessageId = $conn->insert_id;
    
    // Get updated thread info
    $stmt = $conn->prepare("SELECT title FROM threads WHERE id = ?");
    $stmt->bind_param("i", $threadId);
    $stmt->execute();
    $result = $stmt->get_result();
    $thread = $result->fetch_assoc();
    
    // Extract request_id from Python service response if available
    $requestId = $responseData['request_id'] ?? null;
    
    sendJSON([
        'success' => true,
        'thread_id' => $threadId,
        'thread_title' => $thread['title'],
        'is_new_thread' => $isNewThread,
        'request_id' => $requestId,
        'user_message' => [
            'id' => $userMessageId,
            'role' => 'user',
            'content' => $message
        ],
        'ai_message' => [
            'id' => $aiMessageId,
            'role' => 'assistant',
            'content' => $content,
            'data_type' => $dataType,
            'data' => in_array($dataType, ['recommendation', 'upgrade_suggestion'], true) ? json_decode($content, true) : null
        ]
    ]);
}

function generateThreadTitle($userMessage) {
    // Use PHP AI service to generate title (no Python dependency)
    return generateThreadTitleAI($userMessage);
}

// generateAIResponse is no longer needed - we now use processAIMessage() from ai_service.php directly

function generateFallbackResponse($userMessage) {
    $message = strtolower($userMessage);
    
    $fallbackHtml = '
    <div class="smart-recommendation">
        <div class="ai-response-section">
            <div class="ai-message">';
    
    if (strpos($message, 'spec') !== false || strpos($message, 'computer') !== false || strpos($message, 'budget') !== false) {
        $fallbackHtml .= "I'd be happy to help you with computer specifications! Based on your request, I can provide recommendations for components that fit your budget and needs. Could you provide more details about:\n\n- Your budget range\n- Primary use case (gaming, work, development, etc.)\n- Any specific requirements or preferences\n\nThis will help me give you the best recommendations!";
    } else {
        $fallbackHtml .= "Thank you for your message! I'm here to help you with computer specifications and recommendations. Please provide more details about what you're looking for, such as your budget, intended use, and any specific requirements.";
    }
    
    $fallbackHtml .= '
            </div>
        </div>
    </div>';
    
    return $fallbackHtml;
}
?>