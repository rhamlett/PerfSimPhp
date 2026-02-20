<?php
/**
 * =============================================================================
 * TELEMETRY SERVICE — Application Insights Integration via OpenTelemetry
 * =============================================================================
 *
 * PURPOSE:
 *   Provides Application Insights telemetry for PHP applications using
 *   OpenTelemetry SDK. Gracefully handles missing configuration.
 *
 * CONFIGURATION:
 *   Set these App Settings in Azure Portal (or environment variables locally):
 *   - APPLICATIONINSIGHTS_CONNECTION_STRING: Connection string from App Insights resource
 *   - OTEL_SERVICE_NAME: (optional) Custom service name, defaults to "PerfSimPhp"
 *
 * USAGE:
 *   TelemetryService::init();           // Call once at startup
 *   TelemetryService::trackRequest(...) // Track HTTP requests
 *   TelemetryService::trackException()  // Track exceptions
 *   TelemetryService::flush();          // Flush before shutdown
 *
 * @module src/Services/TelemetryService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Azure\Monitor\OpenTelemetry\Exporter\AzureMonitorTraceExporter;

class TelemetryService
{
    private static bool $initialized = false;
    private static bool $enabled = false;
    private static ?TracerInterface $tracer = null;
    private static ?TracerProvider $tracerProvider = null;
    private static mixed $currentSpan = null;
    private static ?ScopeInterface $currentScope = null;
    private static float $requestStartTime = 0;

    /**
     * Environment variable for Application Insights connection string.
     * This is the standard Azure variable name.
     */
    private const CONNECTION_STRING_VAR = 'APPLICATIONINSIGHTS_CONNECTION_STRING';
    
    /**
     * Environment variable for custom service name.
     */
    private const SERVICE_NAME_VAR = 'OTEL_SERVICE_NAME';
    
    /**
     * Default service name if not specified.
     */
    private const DEFAULT_SERVICE_NAME = 'PerfSimPhp';

    /**
     * Initialize the telemetry service.
     * Must be called once at application startup.
     * 
     * Safe to call even if App Insights is not configured - will gracefully
     * disable telemetry and log an info message.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Check if OpenTelemetry packages are installed
        if (!class_exists('OpenTelemetry\SDK\Trace\TracerProvider')) {
            self::$enabled = false;
            // Don't log anything - packages not installed is a normal state
            return;
        }

        // Get connection string from environment
        $connectionString = self::getEnvVar(self::CONNECTION_STRING_VAR);
        
        if (empty($connectionString)) {
            self::$enabled = false;
            EventLogService::info(
                'TELEMETRY',
                'Application Insights disabled: ' . self::CONNECTION_STRING_VAR . ' not set'
            );
            return;
        }

        try {
            // Get service name
            $serviceName = self::getEnvVar(self::SERVICE_NAME_VAR) ?: self::DEFAULT_SERVICE_NAME;

            // Create resource with service info
            $resource = ResourceInfoFactory::defaultResource()->merge(
                ResourceInfo::create(Attributes::create([
                    ResourceAttributes::SERVICE_NAME => $serviceName,
                    ResourceAttributes::SERVICE_VERSION => '1.0.0',
                    ResourceAttributes::DEPLOYMENT_ENVIRONMENT => self::getEnvVar('WEBSITE_SITE_NAME') ? 'azure' : 'local',
                ]))
            );

            // Create Azure Monitor exporter
            $exporter = new AzureMonitorTraceExporter($connectionString);

            // Create tracer provider with exporter
            self::$tracerProvider = TracerProvider::builder()
                ->addSpanProcessor(new SimpleSpanProcessor($exporter))
                ->setResource($resource)
                ->build();

            // Get tracer instance
            self::$tracer = self::$tracerProvider->getTracer($serviceName, '1.0.0');
            
            self::$enabled = true;
            EventLogService::info(
                'TELEMETRY',
                'Application Insights enabled for service: ' . $serviceName
            );
        } catch (\Throwable $e) {
            self::$enabled = false;
            EventLogService::error(
                'TELEMETRY',
                'Failed to initialize Application Insights: ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if telemetry is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Start tracking an HTTP request.
     * Call this at the beginning of request handling.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $uri Request URI
     * @param array $attributes Additional span attributes
     */
    public static function startRequest(string $method, string $uri, array $attributes = []): void
    {
        self::$requestStartTime = microtime(true);
        
        if (!self::$enabled || self::$tracer === null) {
            return;
        }

        try {
            $spanBuilder = self::$tracer->spanBuilder("$method $uri")
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
                ->setAttribute(TraceAttributes::URL_PATH, $uri)
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $_SERVER['SERVER_NAME'] ?? 'localhost');

            // Add custom attributes
            foreach ($attributes as $key => $value) {
                $spanBuilder->setAttribute($key, $value);
            }

            self::$currentSpan = $spanBuilder->startSpan();
            self::$currentScope = self::$currentSpan->activate();
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * End tracking the current request.
     * Call this at the end of request handling.
     *
     * @param int $statusCode HTTP response status code
     * @param bool $success Whether the request was successful
     */
    public static function endRequest(int $statusCode, bool $success = true): void
    {
        if (!self::$enabled || self::$currentSpan === null) {
            return;
        }

        try {
            $duration = (microtime(true) - self::$requestStartTime) * 1000;

            self::$currentSpan->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
            self::$currentSpan->setAttribute('http.duration_ms', $duration);
            
            if (!$success || $statusCode >= 400) {
                self::$currentSpan->setStatus(StatusCode::STATUS_ERROR);
            } else {
                self::$currentSpan->setStatus(StatusCode::STATUS_OK);
            }

            if (self::$currentScope !== null) {
                self::$currentScope->detach();
                self::$currentScope = null;
            }
            
            self::$currentSpan->end();
            self::$currentSpan = null;
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * Track an exception.
     *
     * @param \Throwable $exception The exception to track
     * @param array $attributes Additional attributes
     */
    public static function trackException(\Throwable $exception, array $attributes = []): void
    {
        if (!self::$enabled || self::$tracer === null) {
            return;
        }

        try {
            // If there's an active span, record the exception there
            if (self::$currentSpan !== null) {
                self::$currentSpan->recordException($exception, [
                    'exception.type' => get_class($exception),
                    'exception.message' => $exception->getMessage(),
                    'exception.stacktrace' => $exception->getTraceAsString(),
                    ...$attributes,
                ]);
                self::$currentSpan->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            } else {
                // Create a standalone exception span
                $span = self::$tracer->spanBuilder('Exception: ' . get_class($exception))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->startSpan();
                
                $span->recordException($exception, [
                    'exception.type' => get_class($exception),
                    'exception.message' => $exception->getMessage(),
                    'exception.stacktrace' => $exception->getTraceAsString(),
                    ...$attributes,
                ]);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                $span->end();
            }
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * Track a custom event.
     *
     * @param string $name Event name
     * @param array $attributes Event attributes/properties
     */
    public static function trackEvent(string $name, array $attributes = []): void
    {
        if (!self::$enabled || self::$tracer === null) {
            return;
        }

        try {
            $span = self::$tracer->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            foreach ($attributes as $key => $value) {
                $span->setAttribute($key, $value);
            }

            $span->end();
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * Track a dependency call (external service, database, etc.).
     *
     * @param string $type Dependency type (HTTP, SQL, etc.)
     * @param string $target Target URI or connection string
     * @param string $name Operation name
     * @param float $durationMs Duration in milliseconds
     * @param bool $success Whether the call succeeded
     * @param array $attributes Additional attributes
     */
    public static function trackDependency(
        string $type,
        string $target,
        string $name,
        float $durationMs,
        bool $success = true,
        array $attributes = []
    ): void {
        if (!self::$enabled || self::$tracer === null) {
            return;
        }

        try {
            $span = self::$tracer->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('dependency.type', $type)
                ->setAttribute('dependency.target', $target)
                ->setAttribute('dependency.duration_ms', $durationMs)
                ->setAttribute('dependency.success', $success)
                ->startSpan();

            foreach ($attributes as $key => $value) {
                $span->setAttribute($key, $value);
            }

            $span->setStatus($success ? StatusCode::STATUS_OK : StatusCode::STATUS_ERROR);
            $span->end();
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * Flush pending telemetry data.
     * Call this before application shutdown.
     */
    public static function flush(): void
    {
        if (!self::$enabled || self::$tracerProvider === null) {
            return;
        }

        try {
            // End any active span
            if (self::$currentSpan !== null) {
                if (self::$currentScope !== null) {
                    self::$currentScope->detach();
                    self::$currentScope = null;
                }
                self::$currentSpan->end();
                self::$currentSpan = null;
            }

            // Force flush the tracer provider
            self::$tracerProvider->forceFlush();
        } catch (\Throwable $e) {
            // Silently ignore telemetry errors
        }
    }

    /**
     * Shutdown the telemetry service.
     * Call this at application termination.
     */
    public static function shutdown(): void
    {
        self::flush();
        
        if (self::$tracerProvider !== null) {
            try {
                self::$tracerProvider->shutdown();
            } catch (\Throwable $e) {
                // Silently ignore
            }
            self::$tracerProvider = null;
        }
        
        self::$tracer = null;
        self::$enabled = false;
        self::$initialized = false;
    }

    /**
     * Get an environment variable, checking both getenv() and $_SERVER.
     */
    private static function getEnvVar(string $name): ?string
    {
        // Check getenv first
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        // Check $_SERVER (common in FPM)
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }

        // Check $_ENV
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }

        return null;
    }

    /**
     * Get telemetry status for diagnostics.
     */
    public static function getStatus(): array
    {
        return [
            'initialized' => self::$initialized,
            'enabled' => self::$enabled,
            'packagesInstalled' => class_exists('OpenTelemetry\SDK\Trace\TracerProvider'),
            'connectionStringConfigured' => !empty(self::getEnvVar(self::CONNECTION_STRING_VAR)),
            'serviceName' => self::getEnvVar(self::SERVICE_NAME_VAR) ?: self::DEFAULT_SERVICE_NAME,
        ];
    }
}
