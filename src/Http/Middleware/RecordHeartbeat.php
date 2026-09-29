<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\UserPresenceManager;

/**
 * Records a (throttled) interactive heartbeat for authenticated users of the
 * configured guards. Guests are ignored and nothing is written for them.
 */
final readonly class RecordHeartbeat
{
    public function __construct(
        private AuthFactory $auth,
        private PresenceConfig $config,
        private UserPresenceManager $presence,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The heartbeat endpoint records its own (possibly passive) heartbeat.
        if (! $this->config->heartbeatEnabled() || $request->routeIs('user-presence.heartbeat')) {
            return $response;
        }

        foreach ($this->config->guards() as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Model) {
                $this->presence->heartbeat($user);
            }
        }

        return $response;
    }
}
