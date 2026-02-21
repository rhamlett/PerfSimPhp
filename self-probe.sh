#!/bin/bash
# =============================================================================
# SELF-PROBE SCRIPT — Generates External Traffic for Azure AppLens Visibility
# =============================================================================
#
# PURPOSE:
#   Internal health probes (browser XHR, localhost curl) don't show in Azure AppLens
#   because they don't go through Azure's front-end infrastructure.
#
#   This script runs in the background and periodically curls the app's PUBLIC URL,
#   generating traffic that flows through Azure's load balancer and shows up in:
#     - Azure AppLens
#     - Azure Monitor metrics
#
# USAGE:
#   Called automatically by startup.sh after PHP-FPM starts
#   Can also be run manually: ./self-probe.sh &
#
# ENVIRONMENT:
#   WEBSITE_HOSTNAME — Set by Azure App Service (e.g., myapp.azurewebsites.net)
#
# =============================================================================

# Get the public hostname from Azure environment
HOSTNAME="${WEBSITE_HOSTNAME:-}"
if [ -z "$HOSTNAME" ]; then
    echo "[self-probe] WARNING: WEBSITE_HOSTNAME not set. Not running on Azure App Service?"
    echo "[self-probe] Self-probe disabled (no external URL available)"
    exit 0
fi

# Build the probe URL
PROBE_URL="https://${HOSTNAME}/api/health"

echo "[self-probe] Starting external health probe"
echo "[self-probe] URL: $PROBE_URL"

# Counter for logging
PROBE_COUNT=0

# Main probe loop
while true; do
    # Increment counter
    PROBE_COUNT=$((PROBE_COUNT + 1))
    
    # Make the request (suppress output, capture status)
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 --max-time 10 "$PROBE_URL" 2>/dev/null)
    
    # Log every 60 probes (1 minute) or on error
    if [ $((PROBE_COUNT % 60)) -eq 0 ] || [ "$HTTP_STATUS" != "200" ]; then
        TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
        if [ "$HTTP_STATUS" = "200" ]; then
            echo "[$TIMESTAMP] [self-probe] #$PROBE_COUNT OK (HTTP $HTTP_STATUS)"
        else
            echo "[$TIMESTAMP] [self-probe] #$PROBE_COUNT FAILED (HTTP $HTTP_STATUS)"
        fi
    fi
    
    # Wait 1 second before next probe
    sleep 1
done
