<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores and reads datetimes as UTC regardless of the application timezone.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|null>
 */
final class UtcDateTime implements CastsAttributes
{
    public const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return self::toCarbon($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::toCarbon($value)?->format(self::FORMAT);
    }

    public static function toCarbon(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc(),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC')->utc(),
            is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
            default => null,
        };
    }

    public static function toStorage(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->format(self::FORMAT);
    }
}
