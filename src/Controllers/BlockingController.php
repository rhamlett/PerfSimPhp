<?php
/**
 * =============================================================================
 * BLOCKING CONTROLLER — Request Thread Blocking Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST /api/simulations/blocking → Start blocking mode (body: durationSeconds)
 *
 * PURPOSE:
 *   Demonstrates the sync-over-async antipattern. When triggered, all subsequent
 *   probe requests will experience latency for the specified duration. This
 *   simulates what happens when blocking operations (synchronous I/O, heavy
 *   computation) tie up request handlers.
 *
 * EXAMPLES OF SYNC-OVER-ASYNC IN PHP:
 *   - file_get_contents() to external APIs instead of async HTTP
 *   - Synchronous database queries without connection pooling  
 *   - Heavy computation on the request thread
 *   - Waiting on file locks or inter-process communication
 *
 * @module src/Controllers/BlockingController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\BlockingService;
use PerfSimPhp\Middleware\Validation;
use PerfSimPhp\Utils;

class BlockingController
{
    /**
     * POST /api/simulations/blocking
     * Starts blocking mode for the specified duration.
     * Spawns multiple FPM workers that block (default: 5 workers).
     */
    public static function block(): void
    {
        $body = Utils::getJsonBody();
        $params = Validation::validateBlockingParams($body);

        // Set blocking mode (returns immediately)
        $simulation = BlockingService::block($params);

        $concurrentWorkers = $params['concurrentWorkers'];
        
        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Blocking {$concurrentWorkers} FPM workers for {$params['durationSeconds']}s",
            'status' => $simulation['status'],
            'startedAt' => $simulation['startedAt'],
            'scheduledEndAt' => $simulation['scheduledEndAt'],
            'durationSeconds' => $params['durationSeconds'],
            'concurrentWorkers' => $concurrentWorkers,
        ]);

        // Spawn blocking workers AFTER sending response
        // This prevents the client from waiting for all workers to complete
        if ($concurrentWorkers > 1) {
            // Flush output and disconnect client first
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Spawn (N-1) blocking requests - the initiator worker stays busy coordinating
            BlockingService::spawnConcurrentBlockingRequests(
                $concurrentWorkers - 1,
                $params['durationSeconds']
            );
        }
    }
}
