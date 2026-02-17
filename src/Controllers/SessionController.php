<?php
/**
 * =============================================================================
 * SESSION CONTROLLER — Session Lock Contention REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   POST /api/simulations/session/lock   → Start holding session lock
 *   GET  /api/metrics/probe?session=1    → Probe that uses sessions (blocks if locked)
 *
 * DEMONSTRATES:
 *   PHP's session file locking gotcha. When one request holds session_start()
 *   without releasing it, other requests from the same browser block.
 *
 * @module src/Controllers/SessionController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\SessionLockService;
use PerfSimPhp\Services\EventLogService;
use PerfSimPhp\Middleware\Validation;

class SessionController
{
    /**
     * POST /api/simulations/session/lock
     * Holds the session lock for the specified duration.
     * While held, any request from the same browser using sessions will block.
     */
    public static function lock(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $durationSeconds = Validation::validateOptionalInteger(
            $body['durationSeconds'] ?? null,
            'durationSeconds',
            1,
            120,
            10
        );
        
        // This will hold the session lock for the duration
        $result = SessionLockService::holdSessionLock($durationSeconds);
        
        http_response_code(200);
        echo json_encode([
            'message' => "Session lock held for {$result['actualDuration']}s",
            'sessionId' => $result['sessionId'],
            'requestedDuration' => $result['requestedDuration'],
            'actualDuration' => $result['actualDuration'],
            'pid' => $result['pid'],
            'timestamp' => date('c'),
        ]);
    }
    
    /**
     * GET /api/simulations/session/probe
     * Probe endpoint that uses sessions. Will block if session lock is held.
     * Used to demonstrate session lock contention.
     */
    public static function probe(): void
    {
        $result = SessionLockService::sessionProbe();
        
        echo json_encode([
            'lockWaitMs' => $result['lockWaitMs'],
            'blockedBySession' => $result['lockWaitMs'] > 100,
            'timestamp' => $result['timestamp'],
        ]);
    }
}
