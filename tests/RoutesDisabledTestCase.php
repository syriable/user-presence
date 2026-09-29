<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests;

use Illuminate\Foundation\Application;

abstract class RoutesDisabledTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('user-presence.routes.enabled', false);
        $app['config']->set('user-presence.presence.heartbeat.middleware_groups', []);
    }
}
