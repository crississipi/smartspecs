<?php
require_once __DIR__ . '/../config.php';
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
    
    // Generate AI response using Python service
    $aiResponse = generateAIResponse($message, $threadId);

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
            'data' => $dataType === 'recommendation' ? json_decode($content, true) : null
        ]
    ]);
}

function generateThreadTitle($userMessage) {
    // Call Python AI service to generate title
    $aiServiceUrl = getenv('PYTHON_SERVICE_URL') ?: getenv('AI_SERVICE_URL') ?: 'http://localhost:5000';
    
    $ch = curl_init($aiServiceUrl . '/title');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['message' => $userMessage]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = @curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['title'])) {
            return $data['title'];
        }
    }
    
    // Fallback: use first 50 characters
    return substr($userMessage, 0, 50);
}

function generateAIResponse($userMessage, $threadId = null) {
    // Call Python AI service
    $aiServiceUrl = getenv('PYTHON_SERVICE_URL') ?: getenv('AI_SERVICE_URL') ?: 'http://localhost:5000';
    
    // Get conversation history if thread exists
    $history = [];
    if ($threadId) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT role, content FROM messages WHERE thread_id = ? ORDER BY created_at ASC LIMIT 10");
        $stmt->bind_param("i", $threadId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $history[] = ['role' => $row['role'], 'content' => $row['content']];
        }
    }
    
    $ch = curl_init($aiServiceUrl . '/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message' => $userMessage,
        'history' => $history,
        'thread_id' => $threadId
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout to allow for build generation
    
    $response = @curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Debug: Python AI service response - HTTP Code: $httpCode");
    if ($curlError) {
        error_log("CURL Error: $curlError");
    }
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        error_log("=== DEBUG: generateAIResponse - Parsing Response ===");
        error_log("HTTP Code: $httpCode");
        error_log("Response length: " . strlen($response));
        error_log("JSON Error: " . json_last_error_msg());
        error_log("Response structure: " . json_encode([
            'has_success' => isset($data['success']),
            'success_value' => $data['success'] ?? null,
            'has_data' => isset($data['data']),
            'data_type' => $data['data']['type'] ?? null,
            'has_ai_message' => isset($data['data']['ai_message']),
            'components_count' => isset($data['data']['components']) ? count($data['data']['components']) : 0
        ]));
        
        if (isset($data['success']) && $data['success']) {
            // Handle new structured response format
            if (isset($data['data']['ai_message'])) {
                error_log("DEBUG: Successfully got structured response from Python AI");
                // Return the full JSON response so sendMessage can process it
                return $response; // Return the full JSON string
            } elseif (isset($data['response'])) {
                // Legacy format support
                error_log("DEBUG: Successfully got HTML response from Python AI");
                return $data['response'];
            } else {
                error_log("ERROR: Python AI service returned success but no response data");
                error_log("Available keys in data: " . (isset($data['data']) && is_array($data['data']) ? implode(', ', array_keys($data['data'])) : 'N/A'));
            }
        } else {
            error_log("ERROR: Python AI service returned error: " . ($data['error'] ?? 'Unknown error'));
            error_log("Full response: " . substr($response, 0, 1000));
        }
    } else {
        error_log("ERROR: Python AI service call failed: HTTP $httpCode");
        if ($curlError) {
            error_log("CURL Error details: $curlError");
        }
        error_log("Response: " . substr($response ?? '', 0, 500));
        
        // If we got a response even with non-200 status, try to parse it
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && isset($data['data'])) {
                error_log("DEBUG: Got error response from Python service, returning it");
                return $response; // Return the error response from Python
            }
        }
    }
    
    // Fallback response if AI service is completely unavailable
    error_log("WARNING: Python AI service is completely unavailable, using PHP fallback");
    return json_encode([
        'success' => false,
        'data' => [
            'type' => 'error',
            'ai_message' => generateFallbackResponse($userMessage)
        ]
    ]);
}

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