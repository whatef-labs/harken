<?php

declare(strict_types=1);

/*
 * Integration tests run against a real broker (see docker/compose.yml) and are
 * excluded from the default run. Enable with:
 *
 *   HARKEN_TEST_BROKER_HOST=127.0.0.1 vendor/bin/pest --group=integration
 */

beforeEach(function () {
    if (! getenv('HARKEN_TEST_BROKER_HOST')) {
        $this->markTestSkipped('Set HARKEN_TEST_BROKER_HOST to run broker integration tests.');
    }
});

it('connects, subscribes and receives its own QoS 1 publish')->todo();

it('restores subscriptions after the broker restarts')->todo();

it('connects over TLS with a private CA')->todo();
