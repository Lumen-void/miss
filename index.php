<?php

declare(strict_types=1);

use MisTool\App;

ini_set('memory_limit', '512M');
set_time_limit(300);

require __DIR__ . '/vendor/autoload.php';

$app = new App(__DIR__);
$app->handle();
