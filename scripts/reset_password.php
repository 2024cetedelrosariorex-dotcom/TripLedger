<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
fwrite(STDOUT, "Sign in as Administrator and use Staff Account Management to create accounts or reset passwords. Changes are audited and resets revoke sessions.\n");
