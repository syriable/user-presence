<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\UserPresenceManager;

/**
 * Sets the application locale to the authenticated user's interface locale.
 *
 * When locale detection is enabled and the user has no stored locale, the
 * Accept-Language header is remembered once. A saved choice is never
 * overwritten. Register it after the authentication middleware.
 */
final readonly class ApplyUserLocale
{
    public function __construct(
        private Application $app,
        private PresenceConfig $config,
        private UserPresenceManager $presence,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Model && $this->config->localeEnabled()) {
            $this->presence->detectLocale($user, $request);

            $this->app->setLocale($this->presence->locale($user));
        }

        return $next($request);
    }
}
