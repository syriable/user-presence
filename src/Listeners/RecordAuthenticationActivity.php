<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Listeners;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\UserPresenceManager;

/**
 * Listens to Laravel's authentication events. The authentication flow itself
 * is not modified, and only guards listed in the configuration are tracked.
 */
final readonly class RecordAuthenticationActivity
{
    public function __construct(
        private Container $container,
        private PresenceConfig $config,
        private UserPresenceManager $presence,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof Model || ! $this->config->tracksGuard($event->guard)) {
            return;
        }

        $request = $this->request();

        $this->presence->recordLogin($event->user, $request?->ip());

        if ($request instanceof Request && $this->config->detectLocationOnLogin()) {
            $this->presence->detectLocation($event->user, $request);
        }
    }

    public function handleLogout(Logout|CurrentDeviceLogout $event): void
    {
        if ($event->user instanceof Model && $this->config->tracksGuard($event->guard)) {
            $this->presence->recordLogout($event->user);
        }
    }

    private function request(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }
}
