<?php

declare(strict_types=1);

$requirements = [
    'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'cURL' => extension_loaded('curl'),
    'OpenSSL' => extension_loaded('openssl'),
    'Mbstring' => extension_loaded('mbstring'),
];

$failed = false;
foreach ($requirements as $label => $ok) {
    echo ($ok ? '[OK]   ' : '[ERRO] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
