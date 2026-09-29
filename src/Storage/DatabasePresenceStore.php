<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Data\PresenceTimestamps;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * Keeps presence timestamps in the package table.
 *
 * Durable, queryable (User::online()) and eager-loadable. Heartbeats are
 * throttled before they reach the store, so writes stay infrequent.
 */
final readonly class DatabasePresenceStore implements PresenceStore
{
    public function __construct(private PresenceRepository $records) {}

    public function get(Model $user): PresenceTimestamps
    {
        $record = $this->records->find($user);

        return new PresenceTimestamps($record?->last_seen_at, $record?->last_activity_at);
    }

    public function touch(Model $user, CarbonImmutable $seenAt, ?CarbonImmutable $activityAt = null): void
    {
        $this->records->write($user, array_filter([
            'last_seen_at' => $seenAt,
            'last_activity_at' => $activityAt,
        ]));
    }

    public function forget(Model $user): void
    {
        $this->records->write($user, [
            'last_seen_at' => null,
            'last_activity_at' => null,
        ]);
    }
}
