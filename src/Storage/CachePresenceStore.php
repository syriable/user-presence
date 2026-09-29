<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Data\PresenceTimestamps;
use Syriable\UserPresence\Support\UserIdentifier;

/**
 * Keeps presence timestamps in a Laravel cache store.
 *
 * Heartbeats never touch the database. Timestamps disappear when the cache
 * is flushed or the TTL expires, and online users cannot be queried with SQL.
 */
final readonly class CachePresenceStore implements PresenceStore
{
    public function __construct(
        private Repository $cache,
        private ?int $ttl = null,
    ) {}

    public function get(Model $user): PresenceTimestamps
    {
        $value = $this->cache->get($this->key($user));

        if (! is_array($value)) {
            return PresenceTimestamps::empty();
        }

        return new PresenceTimestamps(
            $this->toCarbon($value['seen'] ?? null),
            $this->toCarbon($value['activity'] ?? null),
        );
    }

    public function touch(Model $user, CarbonImmutable $seenAt, ?CarbonImmutable $activityAt = null): void
    {
        $activityAt ??= $this->get($user)->lastActivityAt;

        $value = [
            'seen' => $seenAt->getTimestamp(),
            'activity' => $activityAt?->getTimestamp(),
        ];

        $this->ttl === null
            ? $this->cache->forever($this->key($user), $value)
            : $this->cache->put($this->key($user), $value, $this->ttl);
    }

    public function forget(Model $user): void
    {
        $this->cache->forget($this->key($user));
    }

    private function key(Model $user): string
    {
        return 'user-presence:'.UserIdentifier::of($user);
    }

    private function toCarbon(mixed $timestamp): ?CarbonImmutable
    {
        return is_int($timestamp) ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
    }
}
