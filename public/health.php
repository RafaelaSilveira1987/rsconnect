<?php

declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header_remove('X-Powered-By');

echo '{"status":"ok"}';
