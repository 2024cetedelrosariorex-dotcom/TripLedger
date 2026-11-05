<?php

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require_once __DIR__ . '/../app/chatbot.php';

$questions = [
    'Show ticket statistics',
    'How many outstanding tickets?',
    'How many returned tickets?',
    'How many issued tickets?',
    'help'
];

foreach ($questions as $question) {
    echo "\n--------------------------------\n";
    echo "QUESTION: " . $question . "\n\n";

    try {
        $result = tripledgerChatbotReply($question);

        echo "SOURCE: " . $result['source'] . "\n";
        echo "RESPONSE:\n" . $result['reply'] . "\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
