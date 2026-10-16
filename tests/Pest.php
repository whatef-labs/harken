<?php

declare(strict_types=1);

use Whatef\Harken\Tests\Pulse\PulseTestCase;
use Whatef\Harken\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

pest()->group('integration')->in('Integration');

pest()->extend(PulseTestCase::class)->in('Pulse');
