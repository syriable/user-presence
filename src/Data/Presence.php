<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Data;

use Carbon\CarbonImmutable;
use Syriable\UserPresence\Enums\PresenceStatus;

/**
 * A read-only snapshot of a user's presence and local context.
 *
 * The snapshot contains personal data. Authorize the viewer before
 * exposing any of it to other users.
 */
final readonly class Presence
{
    /**
     * @param  list<string>  $spokenLanguages
     */
    public function __construct(
        public PresenceStatus $status,
        public ?CarbonImmutable $lastSeenAt,
        public ?CarbonImmutable $lastActivityAt,
        public ?CarbonImmutable $lastLoginAt,
        public ?CarbonImmutable $lastLogoutAt,
        public string $locale,
        public array $spokenLanguages,
        public string $timezone,
        public CarbonImmutable $localTime,
        public ?Location $location,
    ) {}

    public function isOnline(): bool
    {
        return $this->status->isOnline();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'last_seen_at' => $this->lastSeenAt?->toIso8601String(),
            'last_activity_at' => $this->lastActivityAt?->toIso8601String(),
            'last_login_at' => $this->lastLoginAt?->toIso8601String(),
            'last_logout_at' => $this->lastLogoutAt?->toIso8601String(),
            'locale' => $this->locale,
            'spoken_languages' => $this->spokenLanguages,
            'timezone' => $this->timezone,
            'local_time' => $this->localTime->toIso8601String(),
            'location' => $this->location?->toArray(),
        ];
    }
}
