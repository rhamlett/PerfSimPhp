<?php
/**
 * =============================================================================
 * LOAD TEST CONTROLLER — Azure Load Testing Integration REST API
 * =============================================================================
 *
 * ENDPOINTS:
 *   GET /api/loadtest       → Execute load test work (all params optional)
 *   GET /api/loadtest/stats → Current statistics without performing work
 *
 * Designed for Azure Load Testing, JMeter, k6, Gatling.
 *
 * SAFEGUARDS:
 *   - Max duration: 60 seconds per request
 *   - Max degradation multiplier: 30x
 *
 * @module src/Controllers/LoadTestController.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Controllers;

use PerfSimPhp\Services\LoadTestService;

class LoadTestController
{
    /**
     * GET /api/loadtest
     * Executes a load test request with configurable resource consumption.
     *
     * QUERY PARAMETERS (all optional, 5 total):
     *   - targetDurationMs (int)    : Base request duration in ms (default: 1000)
     *   - memorySizeKb (int)        : Memory to allocate in KB (default: 5000)
     *   - cpuWorkMs (int)           : CPU work per cycle in ms (default: 20)
     *   - softLimit (int)           : Concurrent requests before degradation (default: 20)
     *   - degradationFactor (float) : Multiplier per concurrent over limit (default: 1.2)
     *
     * EXAMPLES:
     *   GET /api/loadtest
     *   GET /api/loadtest?targetDurationMs=500&cpuWorkMs=50
     *   GET /api/loadtest?softLimit=10&degradationFactor=1.5
     */
    public static function execute(): void
    {
        $request = [];
        
        // Parse the 5 tunable parameters
        $intParams = ['targetDurationMs', 'memorySizeKb', 'cpuWorkMs', 'softLimit', 'baselineDelayMs'];
        foreach ($intParams as $param) {
            if (isset($_GET[$param]) && is_numeric($_GET[$param])) {
                $request[$param] = (int) $_GET[$param];
            }
        }

        // Parse float parameter
        if (isset($_GET['degradationFactor']) && is_numeric($_GET['degradationFactor'])) {
            $request['degradationFactor'] = (float) $_GET['degradationFactor'];
        }

        try {
            $result = LoadTestService::executeWork($request);
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log("[LoadTestController] " . get_class($e) . ": " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/loadtest/stats
     * Returns current load test statistics without performing work.
     */
    public static function stats(): void
    {
        $stats = LoadTestService::getCurrentStats();
        echo json_encode($stats);
    }
}
