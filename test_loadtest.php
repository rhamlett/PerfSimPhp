<?php
require 'vendor/autoload.php';

use PerfSimPhp\Services\LoadTestService;

echo "Testing LoadTestService with all 5 parameters...\n";
echo "GET /api/loadtest?targetDurationMs=500&memorySizeKb=1000&cpuWorkMs=20&softLimit=20&degradationFactor=1.2\n\n";

try {
    $result = LoadTestService::executeWork([
        'targetDurationMs' => 500,    // Target request duration (ms)
        'memorySizeKb' => 1000,       // Memory to allocate (KB)
        'cpuWorkMs' => 20,            // CPU work per cycle (ms)
        'softLimit' => 20,            // Concurrent requests before degradation
        'degradationFactor' => 1.2,   // Multiplier per concurrent over limit
    ]);
    print_r($result);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
