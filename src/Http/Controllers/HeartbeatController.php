<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\UserPresenceManager;

/**
 * POST endpoint for browser heartbeats. Only ever updates the presence of the
 * authenticated user. Browser-reported timezone and locale are treated as
 * untrusted hints: they are validated and never override a user's choice.
 */
final readonly class HeartbeatController
{
    public function __construct(
        private UserPresenceManager $presence,
        private PresenceConfig $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof Model, 401);

        $request->validate([
            'interactive' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:35'],
        ]);

        $this->presence->heartbeat($user, $request->boolean('interactive', true));

        $timezone = $request->input('timezone');

        if (is_string($timezone) && $timezone !== '') {
            $this->presence->recordDetectedTimezone($user, $timezone);
        }

        $locale = $request->input('locale');

        if (is_string($locale) && $locale !== '') {
            $this->presence->recordDetectedLocale($user, $locale);
        }

        return new JsonResponse([
            'status' => $this->presence->status($user)->value,
            'interval' => $this->config->heartbeatInterval(),
        ]);
    }
}
