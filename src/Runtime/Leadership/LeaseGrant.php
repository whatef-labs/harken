<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Leadership;

/**
 * A successful acquire.
 *
 * $vacantSeconds is how long ago the previous leader was last seen alive
 * (its last renewal or release), measured on the lock backend's clock, or
 * null when the backend cannot tell or there was no previous leader.
 */
final readonly class LeaseGrant
{
    public function __construct(public ?float $vacantSeconds = null) {}
}
