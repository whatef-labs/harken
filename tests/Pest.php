<?php

declare(strict_types=1);

use Whatef\Harken\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

pest()->group('integration')->in('Integration');
