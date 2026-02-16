<?php
/**
 * =============================================================================
 * METRICS CONTROLLER — System Metrics & Probe Endpoints
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/metrics       → Full system metrics snapshot (CPU, memory, process)
 *   GET /api/metrics/probe → Lightweight probe for client-side latency measurement
 *
 * NOTE: Real-time metrics are delivered via AJAX polling from the client.
 *       The client polls /api/metrics every 500ms for dashboard updates.
 *
 * @module src/Controllers/MetricsController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\MetricsService;
use PerfSimPhp\Services\LoadTestService;
use PerfSimPhp\Services\SimulationTrackerService;
use PerfSimPhp\Services\MemoryPressureService;

class MetricsController
{
    /**
     * GET /api/metrics
     * Returns current system metrics snapshot.
     */
    public static function index(): array
    {
        return MetricsService::getMetrics();
    }

    /**
     * GET /api/metrics/probe
     * Probe endpoint for latency monitoring with REAL simulation effects.
     *
     * When simulations are active, this probe performs REAL work that causes
     * REAL latency - no artificial delays. This demonstrates actual performance
     * degradation for educational purposes.
     */
    public static function probe(): array
    {
        $stats = LoadTestService::getCurrentStats();
        $workDone = [];
        
        // Perform REAL work based on active simulations
        // This causes REAL latency that appears in the chart
        
        // CPU stress active: Do real CPU work (hash iterations)
        $cpuSims = SimulationTrackerService::getActiveSimulationsByType('CPU_STRESS');
        if (count($cpuSims) > 0) {
            $maxLoad = 0;
            foreach ($cpuSims as $sim) {
                $maxLoad = max($maxLoad, $sim['parameters']['targetLoadPercent'] ?? 50);
            }
            // Scale iterations based on load percentage (100-1000 iterations)
            $iterations = (int) (($maxLoad / 100) * 1000);
            $hash = 'probe';
            for ($i = 0; $i < $iterations; $i++) {
                $hash = hash('sha256', $hash);
            }
            $workDone['cpu'] = $iterations;
        }
        
        // Blocking active: Do real CPU-bound blocking work (same as BlockingService)
        $blockingSims = SimulationTrackerService::getActiveSimulationsByType('REQUEST_BLOCKING');
        if (count($blockingSims) > 0) {
            // Do substantial CPU-bound work that creates visible latency
            // Each active blocking sim adds ~100-200ms of real hash computation
            // Using high PBKDF2 iterations (10000) to match BlockingService
            $iterations = count($blockingSims) * rand(10, 20);
            for ($i = 0; $i < $iterations; $i++) {
                // Same CPU-intensive work that BlockingService uses (10000 iterations each)
                hash_pbkdf2('sha512', 'blocking-probe', 'salt', 10000, 64, false);
            }
            $workDone['blocking'] = $iterations . ' pbkdf2 iterations';
        }
        
        // Memory pressure active: Touch the allocated memory (read it)
        $memorySims = SimulationTrackerService::getActiveSimulationsByType('MEMORY_PRESSURE');
        if (count($memorySims) > 0) {
            // Read from shared storage to cause real memory access
            $totalMb = MemoryPressureService::getTotalAllocatedMb();
            if ($totalMb > 0) {
                // Access the allocations to prevent optimization
                $allocations = MemoryPressureService::getActiveAllocations();
                $workDone['memory'] = $totalMb . 'MB';
            }
        }
        
        // Slow requests active: Do real I/O work (file operations)
        $slowSims = SimulationTrackerService::getActiveSimulationsByType('SLOW_REQUEST');
        if (count($slowSims) > 0) {
            // Perform real file I/O proportional to active slow requests
            $tempFile = sys_get_temp_dir() . '/probe_' . getmypid() . '.tmp';
            $data = str_repeat('X', 1024 * count($slowSims)); // 1KB per active slow request
            file_put_contents($tempFile, $data);
            $read = file_get_contents($tempFile);
            @unlink($tempFile);
            $workDone['io'] = strlen($read);
        }

        return [
            'ts' => (int) (microtime(true) * 1000),
            'pid' => getmypid(),
            'workDone' => $workDone,
            'loadTest' => [
                'active' => $stats['currentConcurrentRequests'] > 0,
                'concurrent' => $stats['currentConcurrentRequests'],
            ],
        ];
    }
}
