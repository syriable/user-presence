<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Data;

use Carbon\CarbonImmutable;

/**
 * The frequently written presence timestamps held by a presence store.
 */
final readonly class PresenceTimestamps
{
    public function __construct(
        public ?CarbonImmutable $lastSeenAt = null,
        public ?CarbonImmutable $lastActivityAt = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}
