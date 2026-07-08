<?php

declare(strict_types=1);

$key = 'base64:' . base64_encode(random_bytes(32));
echo PHP_EOL . 'APP_KEY=' . $key . PHP_EOL . PHP_EOL;
echo 'Copie a linha acima para o arquivo .env.' . PHP_EOL;
