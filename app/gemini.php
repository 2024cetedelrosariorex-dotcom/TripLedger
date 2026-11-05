<?php

// ==========================================
// TRIPLEDGER GEMINI AI SERVICE
// Server-side only
// ==========================================

require_once __DIR__ . '/ai_settings.php';

/**
 * Generate a response using Gemini AI.
 *
 * Features:
 * - Secure server-side API key
 * - HTTP error handling
 * - Complete response validation
 * - MAX_TOKENS detection
 * - No exposure of internal reasoning
 *
 * Only send fictional or approved aggregate data.
 *
 * @throws RuntimeException
 */
function tripledgerGeminiGenerate(
    string $prompt,
    int $maxOutputTokens = 4096,
    string $feature = 'explanations'
): string {

    // ======================================
    // 1. VALIDATE INPUT
    // ======================================

    $settings = aiRequireEnabled($feature);
    $model = $settings['model'];
    if (!in_array($model, aiAllowedModels(), true)) {
        throw new RuntimeException('Gemini model is not configured. Local search remains available.');
    }
    $prompt = trim($prompt);

    if ($prompt === '') {
        throw new InvalidArgumentException(
            'AI prompt cannot be empty.'
        );
    }

    if (strlen($prompt) > 12000) {
        throw new InvalidArgumentException(
            'AI prompt is too long.'
        );
    }

    if (
        $maxOutputTokens < 1 ||
        $maxOutputTokens > 8192
    ) {
        throw new InvalidArgumentException(
            'Invalid AI response length.'
        );
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException(
            'PHP cURL extension is unavailable.'
        );
    }

    // ======================================
    // 2. LOAD API KEY
    // ======================================

    $keyPath = tripledgerConfig()['gemini']['key_file'] ?? '';

    if (
        !is_file($keyPath) ||
        !is_readable($keyPath)
    ) {
        throw new RuntimeException(
            'Gemini API key configuration is missing.'
        );
    }

    $apiKey = trim(
        (string) file_get_contents($keyPath)
    );

    if ($apiKey === '') {
        throw new RuntimeException(
            'Gemini API key is empty.'
        );
    }

    // ======================================
    // 3. PREPARE API ENDPOINT
    // ======================================

    $endpoint =
        'https://generativelanguage.googleapis.com/v1beta/'
        . 'models/'
        . rawurlencode($model)
        . ':generateContent';

    // ======================================
    // 4. PREPARE REQUEST
    // ======================================

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    [
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $maxOutputTokens,
            'temperature' => 0.2
        ]
    ];

    // No thinkingConfig for now.
    // Keeps the request compatible with the
    // currently configured model and setup.

    $requestBody = json_encode(
        $payload,
        JSON_THROW_ON_ERROR
    );

    // ======================================
    // 5. SEND REQUEST
    // ======================================

    return tripledgerGeminiHttp($endpoint, $apiKey, $requestBody);
}

// Internal transport. Production endpoint and payload are fixed by the caller above.
function tripledgerGeminiHttp(string $endpoint, string $apiKey, string $requestBody, int $timeout = 20): string
{
    $ch = curl_init($endpoint);

    if ($ch === false) {
        throw new RuntimeException(
            'Unable to initialize Gemini request.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ],

        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(1, min(20, $timeout)),

        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
            $responseBody = ($responseBody ?? '') . $chunk;
            return strlen($responseBody) > 131072 ? 0 : strlen($chunk);
        }
    ]);

    try {
        $responseBody = '';
        $response = curl_exec($ch);

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        if ($response === false) {
            throw new RuntimeException(
                'Gemini connection failed. Please try again.'
            );
        }

    } finally {
        curl_close($ch);
    }

    return tripledgerGeminiDecode($responseBody, $httpCode);
}

function tripledgerGeminiDecode(string $response, int $httpCode): string
{
    // ======================================
    // 6. HANDLE HTTP ERRORS
    // ======================================

    if ($httpCode === 429) {
        throw new RuntimeException(
            'Gemini usage limit reached. Try again later.'
        );
    }

    if ($httpCode === 503) {
        // Identifiable temporary error for retry.
        throw new RuntimeException(
            'GEMINI_TEMPORARY_UNAVAILABLE'
        );
    }

    if ($httpCode === 401 || $httpCode === 403) {
        throw new RuntimeException(
            'Gemini API authentication or access failed.'
        );
    }

    if ($httpCode !== 200) {
        throw new RuntimeException(
            'Gemini API request failed (HTTP '
            . $httpCode . ').'
        );
    }

    // ======================================
    // 7. DECODE GEMINI RESPONSE
    // ======================================

    try {
        $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Gemini returned an invalid response.');
    }

    $candidate = $decoded['candidates'][0] ?? null;

    if (!is_array($candidate)) {
        throw new RuntimeException(
            'Gemini returned no response candidate.'
        );
    }

    // ======================================
    // 8. CHECK RESPONSE COMPLETENESS
    // ======================================

    $finishReason = $candidate['finishReason'] ?? '';

    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException(
            'Gemini response exceeded the token limit.'
        );
    }

    if ($finishReason !== 'STOP') {
        throw new RuntimeException(
            'Gemini did not complete its response normally.'
        );
    }

    // ======================================
    // 9. EXTRACT VISIBLE RESPONSE
    // ======================================

    $parts = $candidate['content']['parts'] ?? [];

    if (!is_array($parts)) throw new RuntimeException('Gemini returned invalid content.');
    $answerParts = [];

    foreach ($parts as $part) {

        if (!is_array($part)) {
            continue;
        }

        // Do not expose reasoning / thought content.
        if (!empty($part['thought'])) {
            continue;
        }

        if (
            isset($part['text']) &&
            is_string($part['text'])
        ) {
            $answerParts[] = $part['text'];
        }
    }

    $answer = trim(
        implode("\n", $answerParts)
    );

    if ($answer === '') {
        throw new RuntimeException(
            'Gemini returned no readable response.'
        );
    }

    return $answer;
}

// ==========================================
// 10. AUTOMATIC RETRY FOR HTTP 503
// ==========================================

/**
 * Generate a Gemini response with one retry
 * for temporary HTTP 503 errors.
 *
 * Does not retry invalid API keys,
 * invalid model configuration, or MAX_TOKENS.
 */
function tripledgerGeminiGenerateWithRetry(
    string $prompt,
    int $maxOutputTokens = 4096,
    string $feature = 'explanations'
): string {

    try {

        // First attempt.
        return tripledgerGeminiGenerate(
            $prompt,
            $maxOutputTokens,
            $feature
        );

    } catch (RuntimeException $e) {

        // Retry only when Gemini is temporarily
        // unavailable (HTTP 503).
        if (
            $e->getMessage() !==
            'GEMINI_TEMPORARY_UNAVAILABLE'
        ) {
            throw $e;
        }

        // Wait 1.5 seconds before retrying.
        usleep(1500000);

        try {

            // Second and final attempt.
            return tripledgerGeminiGenerate(
                $prompt,
                $maxOutputTokens,
            $feature
            );

        } catch (RuntimeException $retryError) {

            if (
                $retryError->getMessage() ===
                'GEMINI_TEMPORARY_UNAVAILABLE'
            ) {
                throw new RuntimeException(
                    'Gemini service is temporarily unavailable '
                    . 'after retry.'
                );
            }

            throw $retryError;
        }
    }
}
