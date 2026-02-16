<?php
/**
 * =============================================================================
 * BLOCKING SERVICE — Request Thread Blocking Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Simulates the effect of synchronous/blocking operations (sync-over-async
 *   antipattern) on request latency. This demonstrates what happens when code
 *   performs blocking I/O operations like:
 *   - Synchronous HTTP calls (file_get_contents to external APIs)
 *   - Blocking database queries without connection pooling
 *   - Heavy computation on the request thread
 *
 * HOW IT WORKS:
 *   When blocking is triggered:
 *   1. A time window is set (current time + duration)
 *   2. All probe requests during this window perform CPU-intensive work
 *   3. This causes visible latency spike in the dashboard charts
 *   4. Demonstrates how sync-over-async patterns degrade system performance
 *
 * @module src/Services/BlockingService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;
use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\Services\SimulationTrackerService;

class BlockingService
{
    private const BLOCKING_MODE_KEY = 'perfsim_blocking_mode';

    /**
     * Start blocking mode for the specified duration.
     * All probe requests during this window will perform blocking work.
     *
     * @param array{durationSeconds: int} $params
     * @return array The simulation record
     */
    public static function block(array $params): array
    {
        $durationSeconds = $params['durationSeconds'];
        $endTime = microtime(true) + $durationSeconds;

        // Create simulation record first
        $simulation = SimulationTrackerService::createSimulation(
            'REQUEST_BLOCKING',
            ['type' => 'REQUEST_BLOCKING', 'durationSeconds' => $durationSeconds],
            $durationSeconds
        );

        // Set blocking mode window with simulation ID
        SharedStorage::set(self::BLOCKING_MODE_KEY, [
            'endTime' => $endTime,
            'durationSeconds' => $durationSeconds,
            'startedAt' => microtime(true),
            'simulationId' => $simulation['id'],
        ], $durationSeconds + 60); // TTL slightly longer than duration

        return $simulation;
    }

    /**
     * Check if blocking mode is currently active.
     * If blocking has expired, cleans up and logs completion.
     * Also cleans up any stale REQUEST_BLOCKING simulations that were left behind
     * if the blocking mode key was deleted by TTL before cleanup could run.
     *
     * @return array|null Blocking mode info if active, null otherwise
     */
    public static function getBlockingMode(): ?array
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        
        // If blocking mode key doesn't exist, clean up any stale simulations
        // This handles the case where APCu/file TTL deleted the key before cleanup
        if (!$mode || !isset($mode['endTime'])) {
            self::cleanupStaleSimulations();
            return null;
        }

        if (microtime(true) > $mode['endTime']) {
            // Blocking period has ended - clean up and log completion
            SharedStorage::delete(self::BLOCKING_MODE_KEY);
            
            // Log completion event
            $duration = $mode['durationSeconds'] ?? 0;
            EventLogService::success(
                'SIMULATION_COMPLETED',
                "Request thread blocking completed after {$duration}s",
                $mode['simulationId'] ?? null,
                'REQUEST_BLOCKING'
            );
            
            // Mark simulation as completed in tracker
            if (isset($mode['simulationId'])) {
                SimulationTrackerService::completeSimulation($mode['simulationId']);
            }
            
            return null;
        }

        return $mode;
    }

    /**
     * Perform blocking work if blocking mode is active.
     * Returns the work done for debugging.
     *
     * @return array|null Work done info, or null if not in blocking mode
     */
    public static function performBlockingIfActive(): ?array
    {
        $mode = self::getBlockingMode();
        if (!$mode) {
            return null;
        }

        // Calculate how much work to do based on remaining time
        // More aggressive blocking = more iterations
        $remaining = $mode['endTime'] - microtime(true);
        $total = $mode['durationSeconds'];
        $intensity = max(0.5, min(1.0, $remaining / $total)); // 0.5 to 1.0

        // Do CPU-intensive blocking work
        // ~10-20 iterations of PBKDF2 with 10000 rounds each = 100-400ms latency
        $iterations = (int) (15 * $intensity);
        for ($i = 0; $i < $iterations; $i++) {
            hash_pbkdf2('sha512', 'blocking-probe', 'salt', 10000, 64, false);
        }

        return [
            'iterations' => $iterations,
            'intensity' => round($intensity, 2),
            'remainingSeconds' => round($remaining, 1),
        ];
    }

    /**
     * Stop blocking mode immediately.
     */
    public static function stop(): void
    {
        $mode = SharedStorage::get(self::BLOCKING_MODE_KEY);
        SharedStorage::delete(self::BLOCKING_MODE_KEY);
        
        // Mark simulation as stopped and log
        if ($mode && isset($mode['simulationId'])) {
            SimulationTrackerService::stopSimulation($mode['simulationId']);
            EventLogService::info(
                'SIMULATION_STOPPED',
                'Request thread blocking stopped manually',
                $mode['simulationId'],
                'REQUEST_BLOCKING'
            );
        }
    }

    /**
     * Clean up stale REQUEST_BLOCKING simulations that were left behind.
     * This handles the case where the blocking mode key was deleted by TTL
     * (APCu or file storage) before the cleanup code could run.
     */
    private static function cleanupStaleSimulations(): void
    {
        // Get all REQUEST_BLOCKING simulations that are still marked ACTIVE
        // but have passed their scheduled end time
        $simulations = SimulationTrackerService::getSimulationsInTimeWindow('REQUEST_BLOCKING');
        
        // If there are any active-but-expired blocking simulations, they're stale
        // The getSimulationsInTimeWindow checks scheduledEndAt, so anything returned
        // should still be within its window. But we also need to check for simulations
        // that are ACTIVE but past their scheduledEndAt (not cleaned up).
        $allSims = SharedStorage::get('perfsim_simulations', []);
        $now = \PerfSimPhp\Utils::formatTimestamp();
        
        foreach ($allSims as $id => $sim) {
            if ($sim['type'] === 'REQUEST_BLOCKING' && 
                $sim['status'] === 'ACTIVE' &&
                isset($sim['scheduledEndAt']) &&
                $sim['scheduledEndAt'] < $now) {
                // This simulation is stale - mark it as completed
                SimulationTrackerService::completeSimulation($id);
                $duration = $sim['parameters']['durationSeconds'] ?? 0;
                EventLogService::success(
                    'SIMULATION_COMPLETED',
                    "Request thread blocking completed after {$duration}s (cleanup)",
                    $id,
                    'REQUEST_BLOCKING'
                );
            }
        }
    }
}
