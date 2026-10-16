<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Routing;

/**
 * How the messages of a route are spread across runtime replicas.
 */
enum Distribution: string
{
    /**
     * One replica, the elected leader, subscribes; the others stand by and
     * take over when it stops. Every message is handled once, by one
     * process, in the order it arrived. The default.
     */
    case Leader = 'leader';

    /**
     * An MQTT shared subscription: each message goes to one of the replicas.
     * Messages from one source may be handled by different processes.
     */
    case Shared = 'shared';

    /**
     * Every replica subscribes and handles every message.
     */
    case Broadcast = 'broadcast';
}
