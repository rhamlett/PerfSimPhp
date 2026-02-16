<?php
/**
 * =============================================================================
 * CPU WORKER — Standalone Background Process That Burns One CPU Core at 100%
 * =============================================================================
 *
 * PURPOSE:
 *   This file is the ENTRY POINT for a separately spawned background process.
 *   It is NOT included/required by the main application — it is launched via
 *   shell exec from CpuStressService.
 *   Each instance burns exactly one CPU core at 100% utilization.
 *
 * EXECUTION MODEL:
 *   - Spawned by: CpuStressService::launchWorkers() via shell_exec()
 *   - Each spawned process = 1 OS process = 1 CPU core pinned at 100%
 *   - The parent spawns N workers (one per target core)
 *   - Self-terminates after durationSeconds (passed as CLI argument)
 *
 * USAGE:
 *   php workers/cpu-worker.php <durationSeconds>
 *
 * HOW IT BURNS CPU:
 *   A tight while(true) loop calling hash_pbkdf2() (PBKDF2 with 10,000 iterations).
 *   Each call takes ~5-10ms of pure CPU work. The loop runs until the duration
 *   elapses or a SIGTERM signal is received.
 *
 * SIGNAL HANDLING:
 *   - SIGTERM: graceful shutdown (sets $running = false, loop exits)
 *   - SIGINT:  graceful shutdown (for manual Ctrl+C)
 *   - Duration timeout: self-terminates when durationSeconds elapses
 *
 * @module workers/cpu-worker.php
 */

declare(strict_types=1);

// Read duration from command line argument
$durationSeconds = isset($argv[1]) ? (int) $argv[1] : 60;

if ($durationSeconds <= 0) {
    fwrite(STDERR, "[cpu-worker] Invalid duration: {$durationSeconds}\n");
    exit(1);
}

$running = true;

// Set up signal handlers for graceful shutdown (POSIX systems only)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        $running = false;
    });
}

$pid = getmypid();
fwrite(STDERR, "[cpu-worker] PID={$pid} started, will burn CPU for {$durationSeconds}s\n");

$endTime = microtime(true) + $durationSeconds;

/**
 * Main CPU burn loop. Runs synchronously until stopped.
 *
 * ALGORITHM:
 *   1. Enter tight while loop
 *   2. Each iteration: multiple hash operations to minimize loop overhead
 *   3. Loop exits when duration elapses or signal received
 *
 * Using multiple hash operations per loop iteration reduces the percentage
 * of time spent on loop overhead and time checks, maximizing CPU burn.
 */
$checkInterval = 0;
while ($running && microtime(true) < $endTime) {
    // Batch multiple CPU-intensive operations per loop iteration
    // This reduces time check overhead and maximizes CPU burn
    for ($i = 0; $i < 10; $i++) {
        hash_pbkdf2('sha512', 'password', 'salt', 5000, 64, false);
    }

    // Check for signals less frequently (every ~50ms instead of every ~5ms)
    if (++$checkInterval >= 10 && function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
        $checkInterval = 0;
    }
}

fwrite(STDERR, "[cpu-worker] PID={$pid} finished after {$durationSeconds}s\n");
exit(0);
