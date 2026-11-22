<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TrkMonitor
    |--------------------------------------------------------------------------
    | Monitoring never interferes with the application: when disabled or when
    | credentials are missing, nothing is sent and no exception is thrown.
    */

    'enabled' => env('TRK_MONITOR_ENABLED', true),

    'project_key' => env('TRK_MONITOR_PROJECT_KEY'),

    'project_secret' => env('TRK_MONITOR_PROJECT_SECRET'),

    'environment' => env('TRK_MONITOR_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('TRK_MONITOR_RELEASE'),

    'server' => [
        // Base URL of the TrkMonitor central application, e.g. https://monitor.example.com
        'url' => env('TRK_MONITOR_URL'),
        'timeout' => (float) env('TRK_MONITOR_TIMEOUT', 3),
        'connect_timeout' => (float) env('TRK_MONITOR_CONNECT_TIMEOUT', 2),
        // Sign requests with HMAC instead of sending the secret (recommended).
        'sign_requests' => env('TRK_MONITOR_SIGN_REQUESTS', true),
        'compress' => env('TRK_MONITOR_COMPRESS', true),
        'retries' => (int) env('TRK_MONITOR_RETRIES', 3),
    ],

    'capture' => [
        'exceptions' => true,
        'failed_jobs' => true,
        'database_errors' => true,
        'http_errors' => false,
        'logs' => true,
        'performance' => true,
        'transactions' => true,
        'breadcrumbs' => true,
    ],

    /*
    | Delivery. When the queue is enabled events are pushed to the given connection
    | and queue; the SDK never assumes a dedicated Redis queue exists. When disabled,
    | events are sent after the response has been returned to the user.
    */
    'queue' => [
        'enabled' => env('TRK_MONITOR_QUEUE', true),
        'connection' => env('TRK_MONITOR_QUEUE_CONNECTION'),
        'name' => env('TRK_MONITOR_QUEUE_NAME', 'monitoring'),
    ],

    'sampling' => [
        'errors' => 1.0,
        'transactions' => 0.1,
        'queries' => 0.1,
        'logs' => 1.0,
    ],

    /*
    | Performance. Slow requests, slow queries, failed requests (5xx) and failed or
    | long-running jobs are always reported, regardless of transaction sampling.
    */
    'performance' => [
        'slow_query_threshold_ms' => 500,
        'slow_request_threshold_ms' => 1000,
        'long_running_job_threshold_ms' => 60000,
        'max_spans_per_transaction' => 100,
    ],

    'database' => [
        // Bindings are never sent by default. When enabled they are sanitized and truncated.
        'send_bindings' => false,
        'max_query_length' => 2000,
        'exclude_connections' => [],
        // Queries touching these tables are ignored entirely (no span, no breadcrumb).
        'exclude_tables' => ['jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions'],
    ],

    'jobs' => [
        // Report every failed attempt, or only the final failure (default).
        'report_retries' => false,
        // Additional top-level job payload keys included in the job context.
        'metadata_allowlist' => [],
    ],

    'privacy' => [
        'send_request_body' => false,
        'send_headers' => true,
        'send_cookies' => false,
        'send_ip' => false,
        // Attach the authenticated user's id (never e-mail unless enabled below).
        'send_user' => true,
        'send_user_email' => false,
        // Include source code lines around in-app stack frames.
        'send_source_context' => true,

        'sensitive_fields' => [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'secret',
            'authorization',
            'cookie',
            'api_key',
            'credit_card',
        ],

        // Keys that are never redacted even if they match a sensitive pattern.
        'allowed_fields' => [],
    ],

    'exclude' => [
        // Route names or URIs (wildcards allowed) that are never monitored as transactions.
        'routes' => [],
        'exceptions' => [],
        // Request paths (wildcards allowed) that are never monitored as transactions.
        'paths' => ['up', 'health', 'horizon*', 'telescope*', '_debugbar*', 'pulse*'],
        // Jobs (class names, wildcards allowed) that are never monitored.
        'jobs' => [],
    ],

    'breadcrumbs' => [
        'max' => 50,
        'queries' => true,
        'logs' => true,
        'http_client' => true,
        'queue' => true,
    ],

    'max_payload_size' => 1024 * 1024,

];
