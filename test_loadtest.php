<?php
require 'vendor/autoload.php';

use PerfSimPhp\Services\LoadTestService;

// Standard test with new params
echo "Testing LoadTestService with safe defaults...\n";

try {
    $result = LoadTestService::executeWork([
        'cpuWorkMs' => 20,
        'memorySizeKb' => 1000,
        'fileIoKb' => 20,
        'jsonDepth' => 3,
        'memoryChurnKb' => 100,
        'targetDurationMs' => 500,
    ]);
    print_r($result);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
