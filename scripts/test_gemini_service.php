<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../app/gemini.php';

echo "Testing TripLedger Gemini Service...\n\n";

try {
  
$response = tripledgerGeminiGenerate(
    'In one short sentence, define an outstanding trip ticket in a fictional records management system.',
    800
);

    

    echo "AI Response:\n";
    echo $response . "\n\n";

    echo "SUCCESS: Gemini PHP Service is working!\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
