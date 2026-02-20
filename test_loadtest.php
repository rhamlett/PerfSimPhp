<?php
require 'vendor/autoload.php';

use PerfSimPhp\Services\LoadTestService;

echo "Testing LoadTestService (simplified API)...\n";
echo "GET /api/loadtest?workMs=200&memoryKb=1024\n\n";

try {
    $result = LoadTestService::executeWork([
        'workMs' => 200,       // Duration of CPU work (ms)
        'memoryKb' => 1024,    // Memory to allocate (KB)
    ]);
    print_r($result);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

