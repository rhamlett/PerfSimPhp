<?php
/**
 * =============================================================================
 * LOAD TEST SERVICE — Simple, Non-Blocking Load Testing
 * =============================================================================
 *
 * PURPOSE:
 *   Load test endpoint for Azure Load Testing, JMeter, k6, Gatling.
 *   Performs REAL work (CPU, memory) that shows in metrics.
 *
 * DESIGN PHILOSOPHY:
 *   - Each request does a SHORT burst of real work (50-200ms default)
 *   - Workers return quickly, allowing dashboard polls to succeed
 *   - Load test frameworks hit the endpoint repeatedly for sustained load
 *   - Under heavy load, requests naturally queue (realistic degradation)
 *   - NO shared state, NO file locks, NO complex tracking
 *
 * PARAMETERS (2 tunable):
 *   - workMs (default: 100) — Duration of CPU work in milliseconds
 *   - memoryKb (default: 1024) — Memory to allocate in KB
 *
 * WHAT'S MEASURED:
 *   - Response time includes: queue wait + work time + PHP overhead
 *   - Under load, queue wait increases (natural back-pressure)
 *   - CPU metrics will show real utilization from hash work
 *   - Memory metrics will show allocations
 *
 * @module src/Services/LoadTestService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class LoadTestService
{
    /** Maximum work duration to prevent runaway (5 seconds) */
    private const MAX_WORK_MS = 5000;

    /** Default request parameters */
    private const DEFAULTS = [
        'workMs' => 100,      // Duration of CPU work (ms)
        'memoryKb' => 1024,   // Memory to hold during work (KB)
    ];

    /**
     * Returns the default request parameters.
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Executes load test work - simple CPU + memory allocation.
     *
     * @param array $request Configuration (workMs, memoryKb)
     * @return array Result containing timing information
     */
    public static function executeWork(array $request = []): array
    {
        $startTime = microtime(true);

        // Parse and validate parameters
        $workMs = isset($request['workMs']) ? (int)$request['workMs'] : self::DEFAULTS['workMs'];
        $memoryKb = isset($request['memoryKb']) ? (int)$request['memoryKb'] : self::DEFAULTS['memoryKb'];

        // Legacy parameter support
        if (isset($request['targetDurationMs'])) {
            $workMs = (int)$request['targetDurationMs'];
        }
        if (isset($request['memorySizeKb'])) {
            $memoryKb = (int)$request['memorySizeKb'];
        }

        // Enforce limits
        $workMs = max(10, min($workMs, self::MAX_WORK_MS));
        $memoryKb = max(1, min($memoryKb, 50000)); // Max 50MB

        // Step 1: Allocate memory (held during work)
        $memory = str_repeat('X', $memoryKb * 1024);
        $memoryAllocated = strlen($memory);

        // Step 2: Do real CPU work
        $cpuWorkActual = self::doCpuWork($workMs);

        // Touch memory to prevent optimization
        $touchPos = mt_rand(0, $memoryAllocated - 1);
        $_ = ord($memory[$touchPos]);

        // Calculate total elapsed time
        $totalElapsedMs = (microtime(true) - $startTime) * 1000;

        return [
            'success' => true,
            'requestedWorkMs' => $workMs,
            'actualCpuWorkMs' => round($cpuWorkActual, 2),
            'totalElapsedMs' => round($totalElapsedMs, 2),
            'memoryAllocatedKb' => round($memoryAllocated / 1024, 2),
            'timestamp' => date('c'),
            'workerPid' => getmypid(),
        ];
    }

    /**
     * Gets current statistics from PHP-FPM status (if available).
     */
    public static function getCurrentStats(): array
    {
        // Try to get FPM status for real worker info
        $fpmStats = self::getFpmStats();

        return [
            'activeWorkers' => $fpmStats['active'] ?? 0,
            'idleWorkers' => $fpmStats['idle'] ?? 0,
            'totalWorkers' => $fpmStats['total'] ?? 0,
            'listenQueue' => $fpmStats['listenQueue'] ?? 0,
            'timestamp' => date('c'),
        ];
    }

    /**
     * Performs CPU-intensive work using cryptographic hashing.
     *
     * @param int $targetMs Target milliseconds of work
     * @return float Actual milliseconds of work performed
     */
    private static function doCpuWork(int $targetMs): float
    {
        $startTime = microtime(true);
        $endTime = $startTime + ($targetMs / 1000);

        // Do cryptographic work until target time reached
        // hash_pbkdf2 with 1000 iterations takes ~1-2ms per call
        while (microtime(true) < $endTime) {
            hash_pbkdf2('sha256', 'loadtest', 'salt', 1000, 32, false);
        }

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * Attempts to get PHP-FPM status via local socket.
     */
    private static function getFpmStats(): array
    {
        // Try common FPM status paths
        $statusUrl = 'http://127.0.0.1/fpm-status?json';
        
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 0.5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($statusUrl, false, $ctx);
        if ($response) {
            $data = @json_decode($response, true);
            if (is_array($data)) {
                return [
                    'active' => $data['active processes'] ?? 0,
                    'idle' => $data['idle processes'] ?? 0,
                    'total' => $data['total processes'] ?? 0,
                    'listenQueue' => $data['listen queue'] ?? 0,
                ];
            }
        }

        return [];
    }
}
