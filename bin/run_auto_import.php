#!/usr/bin/env php
<?php

declare(strict_types=1);

use MisTool\Database;
use MisTool\Services\AutoImportService;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$db = new Database($root . '/storage/mis.sqlite');
$db->migrate();
$service = new AutoImportService($db, $root);

$jobId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = (int) substr($arg, strlen('--job-id='));
    }
}

if ($jobId > 0) {
    $service->runJob($jobId);
    echo "Auto-import job {$jobId} finished.\n";
    exit(0);
}

$count = $service->runDueSchedules();
echo "Processed {$count} due auto-import schedule(s).\n";
