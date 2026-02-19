<?php
require 'vendor/autoload.php';

use PerfSimPhp\Services\LoadTestService;

try {
    $result = LoadTestService::executeWork([
        'memorySizeKb' => 1000,
        'cpuWorkMs' => 10,
        'baselineDelayMs' => 100
    ]);
    print_r($result);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
