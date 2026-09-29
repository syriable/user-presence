<?php

declare(strict_types=1);

use Syriable\UserPresence\Tests\RoutesDisabledTestCase;
use Syriable\UserPresence\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(RoutesDisabledTestCase::class)->in('Integration');
