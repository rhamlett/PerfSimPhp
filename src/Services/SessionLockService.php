<?php
/**
 * =============================================================================
 * SESSION LOCK SERVICE — Demonstrates PHP Session Lock Contention
 * =============================================================================
 *
 * PURPOSE:
 *   Demonstrates a common PHP performance gotcha: session file locking.
 *   PHP's default file-based sessions use exclusive locks. When one request
 *   holds session_start() without calling session_write_close(), ALL other
 *   requests from the same browser session are blocked waiting for the lock.
 *
 * HOW IT WORKS:
 *   1. Request A calls session_start() — acquires exclusive lock on session file
 *   2. Request A holds the lock (simulating slow session-using code)
 *   3. Request B from same browser calls session_start() — BLOCKS waiting for lock
 *   4. Request B's latency = time waiting for Request A to release lock
 *
 * REAL-WORLD SCENARIOS:
 *   - Long-running AJAX requests that use sessions
 *   - File uploads with session progress tracking
 *   - Report generation that logs progress to session
 *   - Any code that calls session_start() then does slow work
 *
 * THE FIX (in real applications):
 *   - Call session_write_close() as early as possible
 *   - Use session_start(['read_and_close' => true]) for read-only access
 *   - Switch to Redis/Memcached session handlers (support concurrent reads)
 *
 * @module src/Services/SessionLockService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

class SessionLockService
{
    /**
     * Holds the session lock for the specified duration.
     * Any other request from the same browser that calls session_start()
     * will block until this completes.
     *
     * @param int $durationSeconds How long to hold the lock
     * @return array Status info
     */
    public static function holdSessionLock(int $durationSeconds): array
    {
        $startTime = microtime(true);
        
        // Start session — this acquires the exclusive file lock
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Store some data to make it realistic
        $_SESSION['lock_holder'] = [
            'pid' => getmypid(),
            'started_at' => date('c'),
            'duration' => $durationSeconds,
        ];
        
        // Log the lock acquisition
        EventLogService::info(
            'SESSION_LOCK_ACQUIRED',
            "Session lock acquired, holding for {$durationSeconds}s",
            session_id(),
            'SESSION_LOCK',
            ['sessionId' => session_id(), 'pid' => getmypid()]
        );
        
        // Hold the lock by NOT calling session_write_close()
        // Any request from the same browser that calls session_start() will block here
        sleep($durationSeconds);
        
        // Release the lock
        session_write_close();
        
        $actualDuration = round(microtime(true) - $startTime, 2);
        
        EventLogService::info(
            'SESSION_LOCK_RELEASED',
            "Session lock released after {$actualDuration}s",
            session_id(),
            'SESSION_LOCK'
        );
        
        return [
            'sessionId' => session_id(),
            'requestedDuration' => $durationSeconds,
            'actualDuration' => $actualDuration,
            'pid' => getmypid(),
        ];
    }
    
    /**
     * Performs a session-aware probe. This will block if another request
     * from the same browser is holding the session lock.
     *
     * @return array Timing info including any time spent waiting for lock
     */
    public static function sessionProbe(): array
    {
        $beforeLock = microtime(true);
        
        // This will BLOCK if another request holds the session lock
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $afterLock = microtime(true);
        $lockWaitMs = round(($afterLock - $beforeLock) * 1000, 1);
        
        // Read session data (if any)
        $lockInfo = $_SESSION['lock_holder'] ?? null;
        
        // Release immediately — we just needed to demonstrate the wait
        session_write_close();
        
        return [
            'lockWaitMs' => $lockWaitMs,
            'timestamp' => date('c'),
            'hadLockHolder' => $lockInfo !== null,
        ];
    }
}
