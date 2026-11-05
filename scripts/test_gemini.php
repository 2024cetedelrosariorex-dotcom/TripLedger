<?php
// Use the same configuration, settings and bounded transport as the application.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/test_gemini_service.php';
