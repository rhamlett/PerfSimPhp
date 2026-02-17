/**
 * =============================================================================
 * POLLING CLIENT — AJAX Polling Connection Manager (replaces Socket.IO)
 * =============================================================================
 *
 * PURPOSE:
 *   Manages real-time data updates from the PHP backend via AJAX polling.
 *   PHP-FPM does not support persistent WebSocket connections natively,
 *   so this client polls REST endpoints at regular intervals:
 *   - /api/metrics                → System metrics updates (~250ms)
 *   - /api/admin/events           → Event log entries (~2s)
 *   - /api/metrics/internal-probes → Batch latency measurement (~500ms)
 *
 *   LATENCY PROBING STRATEGY:
 *   To reduce AppLens traffic, latency probes use internal batch probing.
 *   The server performs multiple curl requests to localhost:8080 internally,
 *   which bypass Azure's stamp frontend. This provides ~10 latency samples/sec
 *   while only generating ~2 external requests/sec visible in AppLens.
 *
 * SCRIPT LOADING ORDER:
 *   This file must be loaded BEFORE dashboard.js and charts.js in index.html.
 *   It defines callback hooks (onSocketConnected, onMetricsUpdate, etc.) that
 *   those files implement. This is a simple dependency injection via globals.
 *
 * CONNECTION STRATEGY:
 *   - Uses fetch() for all polling (metrics, events, batch probes)
 *   - Detects connection loss via failed requests
 *   - Auto-reconnects by resuming polling after failures
 *
 * PORTING NOTES:
 *   This file replaces socket-client.js from PerfSimNode. The callback
 *   interface (onSocketConnected, onMetricsUpdate, onEventUpdate, etc.)
 *   is preserved so dashboard.js and charts.js work without changes.
 *   When porting to a backend that supports WebSockets (Java, .NET, Python),
 *   replace this file with a WebSocket/SSE client implementation.
 */

// Connection state
let isConnected = false;
let reconnectAttempts = 0;
const maxReconnectAttempts = 10;

// Polling intervals (milliseconds)
const METRICS_POLL_INTERVAL = 250;
const EVENTS_POLL_INTERVAL = 2000;
// Internal batch probe interval - server does 5 probes @ 100ms each internally
// This results in ~10 latency samples/sec while only 2 external requests/sec hit AppLens
const PROBE_POLL_INTERVAL = 500;
const SESSION_PROBE_POLL_INTERVAL = 100; // Faster probing for session lock demo
const INTERNAL_PROBE_COUNT = 5;
const INTERNAL_PROBE_INTERVAL = 100;

// Session probe mode - when enabled, probes use session endpoint (blocks if session locked)
let sessionProbeEnabled = false;

// Export function to enable/disable session probes
// Restarts probe polling with appropriate interval for the mode
window.setSessionProbeEnabled = function(enabled) {
  const wasEnabled = sessionProbeEnabled;
  sessionProbeEnabled = enabled;
  
  // Restart polling with appropriate interval when mode changes
  if (wasEnabled !== enabled && probePollTimer) {
    clearInterval(probePollTimer);
    const interval = enabled ? SESSION_PROBE_POLL_INTERVAL : PROBE_POLL_INTERVAL;
    probePollTimer = setInterval(probeOnce, interval);
    console.log(`[polling-client] Session probe mode ${enabled ? 'enabled' : 'disabled'}, interval: ${interval}ms`);
  }
};

// Polling timer IDs
let metricsPollTimer = null;
let eventsPollTimer = null;
let probePollTimer = null;

// Track last event count to detect new events
let lastEventCount = 0;

// Track consecutive failures for connection status
let consecutiveFailures = 0;
const MAX_CONSECUTIVE_FAILURES = 3;

/**
 * Initializes the polling client.
 * Tests connectivity first, then starts polling loops.
 */
function initSocket() {
  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connecting...';
    statusEl.className = 'status-reconnecting';
  }

  // Test connectivity with a health check
  fetch('/api/health')
    .then(response => {
      if (response.ok) {
        onConnected();
      } else {
        onConnectionFailed();
      }
    })
    .catch(() => {
      onConnectionFailed();
    });
}

/**
 * Called when initial connection succeeds.
 */
function onConnected() {
  isConnected = true;
  reconnectAttempts = 0;
  consecutiveFailures = 0;

  const statusEl = document.getElementById('connection-status');
  if (statusEl) {
    statusEl.textContent = 'Connected';
    statusEl.className = 'status-connected';
  }
  console.log('[Polling] Connected to server');

  // Start polling loops
  startMetricsPolling();
  startEventsPolling();
  startProbePolling();

  // Add initialization events to the log
  if (typeof addEventToLog === 'function') {
    addEventToLog({ level: 'info', message: 'Dashboard initialized' });
    addEventToLog({ level: 'success', message: 'Connected to metrics hub' });
  }

  // Notify dashboard of connection
  if (typeof onSocketConnected === 'function') {
    onSocketConnected();
  }
}

/**
 * Called when connection attempt fails.
 */
function onConnectionFailed() {
  isConnected = false;
  reconnectAttempts++;

  const statusEl = document.getElementById('connection-status');

  if (reconnectAttempts >= maxReconnectAttempts) {
    if (statusEl) {
      statusEl.textContent = 'Connection Failed';
      statusEl.className = 'status-disconnected';
    }
    console.error('[Polling] Failed to connect after', maxReconnectAttempts, 'attempts');
    return;
  }

  if (statusEl) {
    statusEl.textContent = `Reconnecting (${reconnectAttempts}/${maxReconnectAttempts})...`;
    statusEl.className = 'status-reconnecting';
  }

  // Retry with exponential backoff (1s, 2s, 4s, max 5s)
  const delay = Math.min(1000 * Math.pow(2, reconnectAttempts - 1), 5000);
  setTimeout(initSocket, delay);
}

/**
 * Handles a polling failure. Updates connection status after consecutive failures.
 */
function onPollFailure() {
  consecutiveFailures++;
  if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES && isConnected) {
    isConnected = false;
    const statusEl = document.getElementById('connection-status');
    if (statusEl) {
      statusEl.textContent = 'Disconnected';
      statusEl.className = 'status-disconnected';
    }

    if (typeof addEventToLog === 'function') {
      addEventToLog({ level: 'warning', message: 'Connection lost. Attempting to reconnect...' });
    }

    // Stop polling and attempt reconnection
    stopAllPolling();
    reconnectAttempts = 0;
    setTimeout(initSocket, 1000);
  }
}

/**
 * Handles a polling success. Resets failure tracking and updates status.
 */
function onPollSuccess() {
  if (consecutiveFailures > 0) {
    consecutiveFailures = 0;
    if (!isConnected) {
      isConnected = true;
      const statusEl = document.getElementById('connection-status');
      if (statusEl) {
        statusEl.textContent = 'Connected';
        statusEl.className = 'status-connected';
      }
      if (typeof addEventToLog === 'function') {
        addEventToLog({ level: 'success', message: 'Reconnected to server' });
      }
    }
  }
}

// ============================================================================
// Metrics Polling
// ============================================================================

/**
 * Starts polling /api/metrics at the configured interval.
 */
function startMetricsPolling() {
  if (metricsPollTimer) clearInterval(metricsPollTimer);

  // Poll immediately, then at interval
  pollMetricsOnce();
  metricsPollTimer = setInterval(pollMetricsOnce, METRICS_POLL_INTERVAL);
}

/**
 * Fetches metrics once and dispatches to handlers.
 */
function pollMetricsOnce() {
  fetch('/api/metrics', { cache: 'no-store' })
    .then(response => {
      if (!response.ok) throw new Error('Metrics fetch failed');
      return response.json();
    })
    .then(metrics => {
      onPollSuccess();
      if (typeof onMetricsUpdate === 'function') {
        onMetricsUpdate(metrics);
      }
    })
    .catch(error => {
      // Don't log every failure to avoid console spam
      onPollFailure();
    });
}

// ============================================================================
// Events Polling
// ============================================================================

/**
 * Starts polling /api/admin/events at the configured interval.
 */
function startEventsPolling() {
  if (eventsPollTimer) clearInterval(eventsPollTimer);

  // Initialize event counter and clear log (fresh start on each page load)
  initializeEventLog();
  
  eventsPollTimer = setInterval(pollEventsOnce, EVENTS_POLL_INTERVAL);
}

/**
 * Initialize event log on page load.
 * Sets the event counter to current server count so we only show NEW events.
 * Clears the log display for a fresh start, then adds connection events.
 */
function initializeEventLog() {
  fetch('/api/admin/events?limit=50', { cache: 'no-store' })
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      // Initialize lastEventCount to current server count
      // This marks our "starting point" - we'll only show events after this
      lastEventCount = data.total || data.count || (data.events || []).length;
      
      // Clear event log state (both JS state and DOM) to start fresh
      if (typeof window.clearEventLog === 'function') {
        window.clearEventLog();
      }
      
      // Add initial connection events AFTER clearing
      // These show the user that background monitoring is active
      if (typeof addEventToLog === 'function') {
        addEventToLog({ level: 'info', message: 'Dashboard initialized' });
        addEventToLog({ level: 'success', message: 'Connected to metrics hub' });
      }
    })
    .catch(() => {
      // Silent failure for initial event load
    });
}

/**
 * Fetches events and dispatches new ones to handlers.
 */
function pollEventsOnce() {
  fetch('/api/admin/events?limit=20', { cache: 'no-store' })
    .then(response => {
      if (!response.ok) throw new Error('Events fetch failed');
      return response.json();
    })
    .then(data => {
      onPollSuccess();
      const events = data.events || [];
      // Use total event count (not response count) to detect new events
      const newTotal = data.total || data.count || events.length;

      // Only process if there are new events since we last checked
      if (newTotal > lastEventCount && lastEventCount > 0) {
        // Calculate how many new events arrived
        const newEventsCount = newTotal - lastEventCount;
        // Events are newest-first from the API, so take the first N
        const newEvents = events.slice(0, Math.min(newEventsCount, events.length));
        // Dispatch in chronological order (reverse since API returns newest-first)
        for (let i = newEvents.length - 1; i >= 0; i--) {
          if (typeof onEventUpdate === 'function') {
            onEventUpdate(newEvents[i]);
          }
        }
      }
      lastEventCount = newTotal;
    })
    .catch(() => {
      // Silent failure for events polling
    });
}

// ============================================================================
// Latency Probe Polling
// ============================================================================

/**
 * Starts batch probing via /api/metrics/internal-probes at the configured interval.
 * The server performs multiple internal curl probes to localhost:8080, which
 * bypass Azure's stamp frontend and don't appear in AppLens metrics.
 */
function startProbePolling() {
  if (probePollTimer) clearInterval(probePollTimer);

  probePollTimer = setInterval(probeOnce, PROBE_POLL_INTERVAL);
}

/**
 * Fetches a batch of internal latency probes from the server.
 * The server does multiple curl requests to localhost:8080/api/metrics/probe,
 * avoiding the stamp frontend while still measuring real PHP-FPM latency.
 * 
 * EXCEPTION: When sessionProbeEnabled is true, uses direct browser XHR instead.
 * This is required because session lock contention only works when the probe
 * request shares the browser's session cookie (internal curl creates new sessions).
 */
function probeOnce() {
  // Session probe mode: use direct browser XHR to share session cookie
  // Internal curl probes can't demonstrate session lock because they don't share the browser's session
  if (sessionProbeEnabled) {
    probeOnceDirectXhr();
    return;
  }

  // Normal mode: use internal batch probing (reduces AppLens traffic)
  const params = new URLSearchParams({
    count: INTERNAL_PROBE_COUNT.toString(),
    interval: INTERNAL_PROBE_INTERVAL.toString(),
    session: 'false',
    t: Date.now().toString(),
  });
  const probeUrl = '/api/metrics/internal-probes?' + params.toString();

  fetch(probeUrl, { 
    method: 'GET',
    headers: { 'Accept': 'application/json' },
  })
    .then(response => {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(data => {
      onPollSuccess();

      // Process each probe in the batch
      if (data.probes && Array.isArray(data.probes)) {
        for (const probe of data.probes) {
          if (typeof onProbeLatency === 'function') {
            onProbeLatency({
              latencyMs: probe.latencyMs,
              timestamp: probe.timestamp,
              success: probe.success,
              loadTestActive: probe.loadTestActive || false,
              loadTestConcurrent: probe.loadTestConcurrent || 0,
            });
          }
        }
      }
    })
    .catch(error => {
      // Report a single failure for the batch
      if (typeof onProbeLatency === 'function') {
        onProbeLatency({
          latencyMs: 0,
          timestamp: Date.now(),
          success: false,
          loadTestActive: false,
          loadTestConcurrent: 0,
        });
      }
    });
}

/**
 * Performs a direct browser XHR probe to the session endpoint.
 * Used when sessionProbeEnabled is true, so the probe shares the browser's
 * session cookie and will be blocked by session lock contention.
 * 
 * NOTE: This goes through the stamp frontend (shows in AppLens) but is
 * required for session lock demonstration to work correctly.
 */
function probeOnceDirectXhr() {
  const startTime = performance.now();

  const xhr = new XMLHttpRequest();
  const probeUrl = '/api/simulations/session/probe?t=' + Date.now();
  xhr.open('GET', probeUrl, true);
  xhr.timeout = 60000; // 60 second timeout (session locks can be very long)
  xhr.withCredentials = true; // Ensure cookies are sent

  xhr.onload = function () {
    const endTime = performance.now();
    const latencyMs = endTime - startTime;

    onPollSuccess();

    // Parse response for lock wait info
    let lockWaitMs = 0;
    try {
      const data = JSON.parse(xhr.responseText);
      lockWaitMs = data.lockWaitMs || 0;
    } catch (e) {
      // Ignore parse errors
    }

    // Dispatch to probe handler
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: latencyMs,
        timestamp: Date.now(),
        success: true,
        loadTestActive: false,
        loadTestConcurrent: 0,
        sessionLockWaitMs: lockWaitMs,
      });
    }
  };

  xhr.onerror = function () {
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: 0,
        timestamp: Date.now(),
        success: false,
        loadTestActive: false,
        loadTestConcurrent: 0,
      });
    }
  };

  xhr.ontimeout = function () {
    if (typeof onProbeLatency === 'function') {
      onProbeLatency({
        latencyMs: 60000,
        timestamp: Date.now(),
        success: false,
        loadTestActive: false,
        loadTestConcurrent: 0,
      });
    }
  };

  xhr.send();
}

// ============================================================================
// Utilities
// ============================================================================

/**
 * Stops all polling loops.
 */
function stopAllPolling() {
  if (metricsPollTimer) { clearInterval(metricsPollTimer); metricsPollTimer = null; }
  if (eventsPollTimer) { clearInterval(eventsPollTimer); eventsPollTimer = null; }
  if (probePollTimer) { clearInterval(probePollTimer); probePollTimer = null; }
}

/**
 * Gets the current connection status.
 * @returns {boolean} True if connected
 */
function isSocketConnected() {
  return isConnected;
}

/**
 * Gets a placeholder socket object (compatibility shim for dashboard.js).
 * @returns {null} No socket in polling mode
 */
function getSocket() {
  return null;
}

// Track when tab was last hidden to detect stale data
let tabHiddenAt = null;
const STALE_THRESHOLD_MS = 5000; // Data older than 5s is stale

/**
 * Handles visibility change events.
 * When returning to a backgrounded tab, clears stale data and resumes fresh.
 */
function handleVisibilityChange() {
  if (document.hidden) {
    // Tab is being hidden - record the time
    tabHiddenAt = Date.now();
  } else {
    // Tab is becoming visible again
    if (tabHiddenAt && (Date.now() - tabHiddenAt) > STALE_THRESHOLD_MS) {
      // Was hidden long enough that data is stale - reset charts silently
      if (typeof window.chartsClearAll === 'function') {
        window.chartsClearAll();
      }
    }
    tabHiddenAt = null;
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initSocket);

// Handle tab visibility changes (browser throttles JS when tab is in background)
document.addEventListener('visibilitychange', handleVisibilityChange);
