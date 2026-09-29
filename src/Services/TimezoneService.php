<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\TimezoneResolver;
use Syriable\UserPresence\Data\TimeDifference;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\TimezoneChanged;
use Syriable\UserPresence\Exceptions\InvalidTimezone;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * IANA timezones, local time and time differences between users.
 *
 * Resolution order: stored value (explicit choice first, then browser
 * detected), registered resolvers, configured default (UTC). The server
 * timezone is never assumed to be the user's timezone.
 */
final class TimezoneService
{
    /** @var list<class-string<TimezoneResolver>|TimezoneResolver> */
    private array $resolvers = [];

    /** @var array<string, true>|null */
    private static ?array $identifiers = null;

    public function __construct(
        private readonly PresenceConfig $config,
        private readonly PresenceRepository $records,
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<TimezoneResolver>|TimezoneResolver  $resolver
     */
    public function registerResolver(string|TimezoneResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    public function timezone(Model $user): string
    {
        if (! $this->config->timezoneEnabled()) {
            return $this->defaultTimezone();
        }

        $stored = $this->records->find($user)?->timezone;

        if ($stored !== null && self::isValid($stored)) {
            return $stored;
        }

        foreach ($this->resolvers() as $resolver) {
            $resolved = $resolver->resolve($user);

            if ($resolved !== null && self::isValid($resolved)) {
                return $resolved;
            }
        }

        return $this->defaultTimezone();
    }

    /**
     * Where the stored timezone came from, or null when none is stored.
     */
    public function source(Model $user): ?ContextSource
    {
        return $this->config->timezoneEnabled() ? $this->records->find($user)?->timezone_source : null;
    }

    /**
     * Save the user's chosen timezone. Pass null to clear it.
     *
     * @throws InvalidTimezone
     */
    public function setTimezone(Model $user, ?string $timezone): void
    {
        if (! $this->config->timezoneEnabled()) {
            return;
        }

        if ($timezone !== null && ! self::isValid($timezone)) {
            throw InvalidTimezone::make($timezone);
        }

        $this->store($user, $timezone, $timezone === null ? null : ContextSource::User);
    }

    /**
     * Remember a timezone reported by the browser. Ignored when the user has
     * chosen a timezone themselves or the value is not a valid identifier.
     */
    public function recordDetected(Model $user, string $timezone): bool
    {
        if (! $this->config->timezoneEnabled() || ! $this->config->detectTimezone() || ! self::isValid($timezone)) {
            return false;
        }

        $record = $this->records->find($user);

        if ($record?->timezone_source === ContextSource::User) {
            return false;
        }

        if ($record?->timezone !== $timezone) {
            $this->store($user, $timezone, ContextSource::Browser);
        }

        return true;
    }

    /**
     * The user's local time now, or the given moment converted to their timezone.
     */
    public function localTime(Model $user, ?DateTimeInterface $at = null): CarbonImmutable
    {
        $at = $at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return $at->setTimezone($this->timezone($user));
    }

    /**
     * How far $to's clock is ahead of (positive) or behind (negative) $from's
     * clock at the given moment (now by default).
     */
    public function difference(Model $from, Model $to, ?DateTimeInterface $at = null): TimeDifference
    {
        $at = ($at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::now())->utc();
        $fromTimezone = $this->timezone($from);
        $toTimezone = $this->timezone($to);

        return new TimeDifference(
            $fromTimezone,
            $toTimezone,
            $this->offset($toTimezone, $at) - $this->offset($fromTimezone, $at),
            $at,
        );
    }

    public function defaultTimezone(): string
    {
        $default = $this->config->defaultTimezone();

        return self::isValid($default) ? $default : 'UTC';
    }

    /**
     * Whether the value is a canonical IANA identifier ("Europe/Berlin").
     * Fixed offsets ("+01:00") and abbreviations ("CET") are rejected.
     */
    public static function isValid(string $timezone): bool
    {
        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);

        return isset(self::$identifiers[$timezone]);
    }

    private function offset(string $timezone, DateTimeInterface $at): int
    {
        return new DateTimeZone($timezone)->getOffset($at);
    }

    private function store(Model $user, ?string $timezone, ?ContextSource $source): void
    {
        $previous = $this->records->find($user)?->timezone;

        $this->records->write($user, ['timezone' => $timezone, 'timezone_source' => $source]);

        if ($previous !== $timezone) {
            TimezoneChanged::dispatch($user, $timezone, $previous, $source);
        }
    }

    /**
     * @return iterable<TimezoneResolver>
     */
    private function resolvers(): iterable
    {
        foreach ([...$this->config->timezoneResolvers(), ...$this->resolvers] as $resolver) {
            $resolver = is_string($resolver) ? $this->container->make($resolver) : $resolver;

            if ($resolver instanceof TimezoneResolver) {
                yield $resolver;
            }
        }
    }
}
