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
 *   - memoryKb (default: 5000) — Memory to allocate in KB (5MB)
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

use PerfSimPhp\Services\EventLogService;

class LoadTestService
{
    /** Maximum work duration to prevent runaway (5 seconds) */
    private const MAX_WORK_MS = 5000;

    /** Default request parameters */
    private const DEFAULTS = [
        'workMs' => 100,      // Duration of CPU work (ms)
        'memoryKb' => 5000,   // Memory to hold during work (KB) - 5MB default
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

        // Log to event log (sampled - ~1% of requests to avoid flooding)
        if (mt_rand(1, 100) === 1) {
            try {
                EventLogService::info(
                    'LOAD_TEST_REQUEST',
                    sprintf('Load test: %dms work, %dKB mem, %.0fms total (pid %d)', 
                        $workMs, $memoryKb, $totalElapsedMs, getmypid())
                );
            } catch (\Throwable $e) {
                // Silently skip - event log is nice-to-have
            }
        }

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
     * Gets current statistics (simplified - no concurrent tracking).
     * Returns format expected by MetricsController probe endpoints.
     */
    public static function getCurrentStats(): array
    {
        // No concurrent tracking in simplified version
        // Return format compatible with probe endpoints
        return [
            'currentConcurrentRequests' => 0,
            'totalRequestsProcessed' => 0,
            'totalExceptionsThrown' => 0,
            'averageResponseTimeMs' => 0,
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
}
