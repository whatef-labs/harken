<?php

declare(strict_types=1);

use Whatef\Harken\Facades\Harken;
use Whatef\Harken\Tests\Fixtures\FailingHandler;
use Whatef\Harken\Tests\Fixtures\RecordingHandler;
use Whatef\Harken\Tests\Fixtures\SlowJobHandler;

Harken::topic('sensors/{id}/temperature', RecordingHandler::class)->qos(1);

Harken::topic('sensors/{id}/report', SlowJobHandler::class)->qos(1)->shared('ingest')->queued();

Harken::topic('sensors/{id}/broken', FailingHandler::class);

Harken::every(60, static fn () => null);
