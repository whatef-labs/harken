<?php

use Illuminate\Support\Str;

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
    | and publish.
    |
    | protocol is the MQTT version this connection speaks: "5.0" (default) or
    | "3.1.1". The broker serves each client in the version it connected
    | with, so devices on 3.1.1 and Harken on 5.0 can share a broker. A
    | broker without MQTT 5 refuses the connection at startup.
    |
    | When client_id is empty, a unique id is generated per process as
    | "{prefix}-{hostname}-{pid}-{random}" so replicas started from the same
    | configuration do not kick each other off the broker. The prefix
    | defaults to the application name, so applications sharing a broker do
    | not collide. Set an explicit
    | client_id only for a single process that needs a persistent session
    | (clean_session = false); on MQTT 5, session_expiry then sets how many
    | seconds the broker keeps that session while the process is away.
    |
    | dns is the nameserver used to resolve host; empty uses the system
    | configuration. It is not used when host is an IP address.
    |
    */

    'connections' => [

        'default' => [
            'host' => env('HARKEN_HOST', '127.0.0.1'),
            'port' => (int) env('HARKEN_PORT', 1883),
            'protocol' => env('HARKEN_PROTOCOL', '5.0'),
            'client_id' => env('HARKEN_CLIENT_ID'),
            'client_id_prefix' => env('HARKEN_CLIENT_ID_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-harken'),
            'username' => env('HARKEN_USERNAME'),
            'password' => env('HARKEN_PASSWORD'),
            'keepalive' => (int) env('HARKEN_KEEPALIVE', 60),
            'clean_session' => (bool) env('HARKEN_CLEAN_SESSION', true),
            'session_expiry' => (int) env('HARKEN_SESSION_EXPIRY', 0),
            'connect_timeout' => 5.0,
            'dns' => env('HARKEN_DNS'),

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

            /*
             * Leader routes (the default distribution) are received by one
             * elected replica on a second connection with a fixed client id
             * and a persistent session, so the broker queues QoS 1 messages
             * while the leader changes. The lock is "redis" (a connection in
             * config/database.php redis), "pgsql" (a database connection;
             * PostgreSQL 14+ and ext-pgsql) or "none" (single replica only).
             * A standby replica takes over within the lease after a crash,
             * and within retry_interval after a graceful stop.
             */
            'leader' => [
                'client_id' => env('HARKEN_LEADER_CLIENT_ID'), // default: "{client_id_prefix}-leader"
                'session_expiry' => (int) env('HARKEN_LEADER_SESSION_EXPIRY', 300),
                'lock' => env('HARKEN_LEADER_LOCK', 'redis'),
                'lock_connection' => env('HARKEN_LEADER_LOCK_CONNECTION'), // default: "default" (redis), "pgsql" (pgsql)
                'lease' => (float) env('HARKEN_LEADER_LEASE', 5),
                'retry_interval' => 0.25,
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
    | batch is also the most entries one replica publishes at once; max_rate
    | (messages per second, empty for no limit) paces a large backlog so it
    | does not overflow subscribers' session queues on the broker. Entries
    | left pending by a replica that died are taken over after claim_idle_ms.
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
        'max_rate' => env('HARKEN_OUTBOX_MAX_RATE'),
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
