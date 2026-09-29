<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Data;

use Carbon\CarbonImmutable;

/**
 * The difference between two users' local clocks at a given instant.
 *
 * Offsets are calculated from current timezone rules at that instant, so the
 * result is correct across daylight saving time transitions. Do not persist
 * it as a permanent difference between two users.
 */
final readonly class TimeDifference
{
    public function __construct(
        public string $fromTimezone,
        public string $toTimezone,
        public int $seconds,
        public CarbonImmutable $at,
    ) {}

    /**
     * Minutes the second user's clock is ahead (positive) or behind (negative).
     */
    public function inMinutes(): int
    {
        return intdiv($this->seconds, 60);
    }

    public function inHours(): float
    {
        return $this->seconds / 3600;
    }

    public function isAhead(): bool
    {
        return $this->seconds > 0;
    }

    public function isBehind(): bool
    {
        return $this->seconds < 0;
    }

    public function isSame(): bool
    {
        return $this->seconds === 0;
    }

    /**
     * The difference as a signed offset, for example "+05:30" or "-06:00".
     */
    public function format(): string
    {
        $minutes = intdiv(abs($this->seconds), 60);

        return sprintf('%s%02d:%02d', $this->seconds < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return array{from_timezone: string, to_timezone: string, seconds: int, formatted: string, at: string}
     */
    public function toArray(): array
    {
        return [
            'from_timezone' => $this->fromTimezone,
            'to_timezone' => $this->toTimezone,
            'seconds' => $this->seconds,
            'formatted' => $this->format(),
            'at' => $this->at->toIso8601String(),
        ];
    }
}
