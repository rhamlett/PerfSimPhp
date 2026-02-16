<?php
/**
 * =============================================================================
 * SLOW CONTROLLER — Slow Request Simulation REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/simulations/slow?delaySeconds=N&blockingPattern=P
 *
 * Uses GET to allow easy testing from browsers and the dashboard.
 *
 * BLOCKING PATTERNS:
 *   - sleep (default): idle wait — FPM worker held but no CPU used
 *   - cpu_intensive:   CPU-bound — burns CPU for entire duration
 *   - file_io:         I/O-bound — intensive file read/write
 *
 * @module src/Controllers/SlowController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\SlowRequestService;
use PerfSimPhp\Middleware\Validation;
use PerfSimPhp\Config;

class SlowController
{
    /**
     * GET /api/simulations/slow
     * Returns a response after an artificial delay.
     */
    public static function slow(): void
    {
        $params = Validation::validateSlowRequestParams($_GET);

        // Execute the slow request (this blocks synchronously)
        $simulation = SlowRequestService::delay($params);

        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Response delayed by {$params['delaySeconds']}s using {$params['blockingPattern']} pattern",
            'status' => $simulation['status'],
            'requestedDelaySeconds' => $params['delaySeconds'],
            'blockingPattern' => $params['blockingPattern'],
            'actualDurationMs' => isset($simulation['stoppedAt'], $simulation['startedAt'])
                ? (int) ((strtotime($simulation['stoppedAt']) - strtotime($simulation['startedAt'])) * 1000)
                : null,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * POST /api/simulations/slow/start
     * Starts a slow request with JSON body parameters.
     */
    public static function start(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $params = Validation::validateSlowRequestParams($body);

        // Execute the slow request (this blocks synchronously)
        $simulation = SlowRequestService::delay($params);

        http_response_code(201);
        echo json_encode([
            'id' => $simulation['id'],
            'type' => $simulation['type'],
            'message' => "Slow request started: {$params['delaySeconds']}s using {$params['blockingPattern']} pattern",
            'status' => $simulation['status'],
            'parameters' => $params,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * POST /api/simulations/slow/stop
     * Stops slow requests (no-op since slow requests are synchronous and self-terminating).
     */
    public static function stop(): void
    {
        echo json_encode([
            'message' => 'Slow request simulation acknowledged (requests are synchronous and self-terminating)',
            'timestamp' => date('c'),
        ]);
    }
}
