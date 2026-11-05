<?php
// Isolated loopback test server only; never place test/ under Apache DocumentRoot.
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
sleep(3);
header('Content-Type: application/json');
echo '{}';
