<?php

// ==========================================
// TRIPLEDGER CHATBOT API
// Authorized Staff only
// ==========================================

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/chatbot.php';

// Require a valid staff session.
// Administrators do not have access to
// staff-only operational features.
requireStaff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chatbotJson(
    int $status,
    array $data
): void {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    chatbotJson(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Request size cap.
// Browser clients should send a short JSON message.
if (
    isset($_SERVER['CONTENT_LENGTH']) &&
    (int) $_SERVER['CONTENT_LENGTH'] > 4096
) {
    chatbotJson(413, [
        'success' => false,
        'message' => 'Request is too large.'
    ]);
}

$contentType = strtolower(
    trim(explode(
        ';',
        $_SERVER['CONTENT_TYPE'] ?? ''
    )[0])
);

if ($contentType !== 'application/json') {
    chatbotJson(415, [
        'success' => false,
        'message' => 'JSON content type is required.'
    ]);
}

$body = file_get_contents(
    'php://input',
    false,
    null,
    0,
    4097
);

if ($body === false || strlen($body) > 4096) {
    chatbotJson(413, [
        'success' => false,
        'message' => 'Request is too large.'
    ]);
}

$data = json_decode($body, true);

if (!is_array($data)) {
    chatbotJson(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

// ==========================================
// CSRF VALIDATION
// ==========================================

$submittedToken = $data['csrf_token'] ?? null;

if (
    !is_string($submittedToken) ||
    !hash_equals(csrfToken(), $submittedToken)
) {
    chatbotJson(403, [
        'success' => false,
        'message' => 'Invalid security token. Refresh the page.'
    ]);
}

// ==========================================
// INPUT VALIDATION
// ==========================================

$message = $data['message'] ?? null;

if (!is_string($message)) {
    chatbotJson(400, [
        'success' => false,
        'message' => 'Invalid message.'
    ]);
}

$message = trim($message);

if ($message === '' || strlen($message) > 500) {
    chatbotJson(422, [
        'success' => false,
        'message' => 'Enter a question between 1 and 500 bytes.'
    ]);
}

// ==========================================
// BASIC SESSION RATE LIMIT
// ==========================================

$now = time();

if (
    !isset($_SESSION['chatbot_requests']) ||
    !is_array($_SESSION['chatbot_requests'])
) {
    $_SESSION['chatbot_requests'] = [];
}

// Keep timestamps from the past hour.
$_SESSION['chatbot_requests'] = array_values(
    array_filter(
        $_SESSION['chatbot_requests'],
        static function ($timestamp) use ($now): bool {
            return is_int($timestamp)
                && $timestamp > $now - 3600;
        }
    )
);

if (count($_SESSION['chatbot_requests']) >= 20) {
    chatbotJson(429, [
        'success' => false,
        'message' =>
            'Chatbot hourly limit reached. Please try later.'
    ]);
}

$lastRequest = end($_SESSION['chatbot_requests']);

if (
    $lastRequest !== false &&
    $now - $lastRequest < 3
) {
    chatbotJson(429, [
        'success' => false,
        'message' =>
            'Please wait a few seconds before sending another message.'
    ]);
}

$_SESSION['chatbot_requests'][] = $now;

// ==========================================
// GENERATE CHATBOT RESPONSE
// ==========================================

try {
    $result = tripledgerChatbotReply($message);

    chatbotJson(200, [
        'success' => true,
        'reply' => $result['reply'],
        'source' => $result['source']
    ]);

} catch (Throwable $e) {
    error_log(
        'TripLedger chatbot error: ' .
        $e->getMessage()
    );

    chatbotJson(503, [
        'success' => false,
        'message' =>
            'The AI Assistant is temporarily unavailable. ' .
            'Please try again.'
    ]);
}
