<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The broker connection used by `php artisan harken:start` when no
    | --connection option is given.
    |
    */

    'default' => env('HARKEN_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Broker Connections
    |--------------------------------------------------------------------------
    |
    | Each runtime process holds one connection and uses it to both subscribe
    | and publish. When client_id is empty, a unique id is generated per
    | process as "{prefix}-{hostname}-{pid}-{random}" so replicas started from
    | the same configuration do not kick each other off the broker. Set an
    | explicit client_id only for a single process that needs a persistent
    | session (clean_session = false).
    |
    */

    'connections' => [

        'default' => [
            'host' => env('HARKEN_HOST', '127.0.0.1'),
            'port' => (int) env('HARKEN_PORT', 1883),
            'protocol' => '3.1.1',
            'client_id' => env('HARKEN_CLIENT_ID'),
            'client_id_prefix' => env('HARKEN_CLIENT_ID_PREFIX', 'harken'),
            'username' => env('HARKEN_USERNAME'),
            'password' => env('HARKEN_PASSWORD'),
            'keepalive' => (int) env('HARKEN_KEEPALIVE', 60),
            'clean_session' => (bool) env('HARKEN_CLEAN_SESSION', true),
            'connect_timeout' => 5.0,

            'tls' => [
                'enabled' => (bool) env('HARKEN_TLS', false),
                'cafile' => env('HARKEN_TLS_CAFILE'),
                'local_cert' => env('HARKEN_TLS_CERT'),
                'local_pk' => env('HARKEN_TLS_KEY'),
                'passphrase' => env('HARKEN_TLS_PASSPHRASE'),
                'verify_peer' => (bool) env('HARKEN_TLS_VERIFY_PEER', true),
                'verify_peer_name' => (bool) env('HARKEN_TLS_VERIFY_PEER_NAME', true),
                'peer_name' => env('HARKEN_TLS_PEER_NAME'),
            ],

            'reconnect' => [
                'initial_delay' => 1.0,
                'max_delay' => 60.0,
                'multiplier' => 2.0,
                'jitter' => 0.5,
                'max_attempts' => null,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The file that registers topic handlers with Harken::topic() and periodic
    | tasks with Harken::every().
    |
    */

    'routes' => base_path('routes/harken.php'),

    /*
    |--------------------------------------------------------------------------
    | Handlers
    |--------------------------------------------------------------------------
    |
    | Inline handlers run on the event loop. Any handler slower than this
    | threshold is logged, because it delays every other message, timer and
    | publish in the process. Move such handlers to the queue with ->queued().
    |
    */

    'handlers' => [
        'slow_threshold_ms' => (int) env('HARKEN_SLOW_HANDLER_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    |
    | Harken::publish() called outside the runtime (HTTP requests, jobs)
    | appends to a Redis stream. Every runtime replica consumes the streams
    | as one consumer group and acknowledges an entry only after the broker
    | confirmed it, so delivery is at least once. The urgent stream is always
    | drained before the normal one.
    |
    */

    'outbox' => [
        'redis' => env('HARKEN_OUTBOX_REDIS', 'default'),
        'streams' => [
            'urgent' => 'harken:outbox:urgent',
            'normal' => 'harken:outbox:normal',
        ],
        'group' => 'harken',
        'block_ms' => 1000,
        'batch' => 100,
        'claim_idle_ms' => 30000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Sources
    |--------------------------------------------------------------------------
    |
    | The runtime has no idle tick to tune. The event loop waits on its
    | sockets and timers and wakes as soon as one of them is ready, so a
    | broker message, an outbox entry or a notification is handled at once.
    |
    | PostgreSQL LISTEN/NOTIFY is watched the same way when ext-pgsql is
    | loaded. Without it, the source polls through PDO every
    | poll_interval_ms milliseconds.
    |
    */

    'sources' => [
        'pgsql' => [
            'poll_interval_ms' => (int) env('HARKEN_PGSQL_POLL_MS', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Process Lifecycle
    |--------------------------------------------------------------------------
    |
    | grace: seconds to finish in-flight work after SIGTERM or a restart.
    | restart_check_interval: how often the flag set by harken:restart is read.
    |
    */

    'shutdown' => [
        'grace' => 10,
    ],

    'restart_check_interval' => 5,

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Each process writes its state (connection state, last loop tick,
    | subscriptions, memory) to the cache. A process whose last heartbeat is
    | older than stale_after seconds is reported unhealthy.
    |
    */

    'health' => [
        'cache_store' => env('HARKEN_HEALTH_STORE'),
        'interval' => 5,
        'stale_after' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging & Metrics
    |--------------------------------------------------------------------------
    |
    | Credentials are never logged. Message payloads are logged only when
    | log_payloads is enabled.
    |
    */

    'logging' => [
        'channel' => env('HARKEN_LOG_CHANNEL'),
        'log_payloads' => (bool) env('HARKEN_LOG_PAYLOADS', false),
    ],

    'metrics' => [
        'driver' => env('HARKEN_METRICS_DRIVER', 'null'),
    ],

];
