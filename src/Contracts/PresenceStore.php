<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Data\PresenceTimestamps;

/**
 * Stores the frequently written presence timestamps (last seen / last activity).
 *
 * Throttling, online calculation and events are handled by the package;
 * a store only persists and returns timestamps. All timestamps are UTC.
 */
interface PresenceStore
{
    public function get(Model $user): PresenceTimestamps;

    /**
     * Record that the user was seen at $seenAt. When $activityAt is null the
     * previously stored activity timestamp must be kept.
     */
    public function touch(Model $user, CarbonImmutable $seenAt, ?CarbonImmutable $activityAt = null): void;

    public function forget(Model $user): void;
}
