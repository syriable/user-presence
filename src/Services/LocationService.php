<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Locale;
use Syriable\UserPresence\Contracts\LocationProvider;
use Syriable\UserPresence\Data\Location;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\LocationChanged;
use Syriable\UserPresence\Exceptions\InvalidLocation;
use Syriable\UserPresence\Models\PresenceRecord;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;
use Throwable;

/**
 * Optional, coarse city / country information.
 *
 * Nothing is collected, stored or returned while location is disabled.
 * A location entered by the user always wins over a detected one.
 */
final class LocationService
{
    private const int MAX_LENGTH = 120;

    /** Codes CDNs use for "unknown" or "Tor" that are not countries. */
    private const array NON_COUNTRIES = ['XX', 'T1', 'ZZ'];

    /** @var list<class-string<LocationProvider>|LocationProvider> */
    private array $providers = [];

    public function __construct(
        private readonly PresenceConfig $config,
        private readonly PresenceRepository $records,
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<LocationProvider>|LocationProvider  $provider
     */
    public function registerProvider(string|LocationProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function location(Model $user): ?Location
    {
        if (! $this->config->locationEnabled()) {
            return null;
        }

        $record = $this->records->find($user);

        return $record instanceof PresenceRecord ? $this->fromRecord($record) : null;
    }

    /**
     * Save a location provided by the user. Pass null to clear it.
     *
     * @throws InvalidLocation
     */
    public function setLocation(Model $user, ?Location $location): void
    {
        if (! $this->config->locationEnabled()) {
            return;
        }

        if ($location instanceof Location) {
            $countryCode = $location->countryCode === null ? null : trim($location->countryCode);

            if ($countryCode !== null && $countryCode !== '' && $this->countryCode($countryCode) === null) {
                throw InvalidLocation::countryCode($countryCode);
            }

            $location = $this->sanitize($location->withSource(ContextSource::User));
        }

        $this->store($user, $location);
    }

    /**
     * Ask the registered providers for the request's location and store the
     * first usable answer, unless the user entered a location themselves.
     */
    public function detect(Model $user, Request $request): ?Location
    {
        if (! $this->config->locationEnabled()) {
            return null;
        }

        if ($this->records->find($user)?->location_source === ContextSource::User) {
            return null;
        }

        foreach ($this->providers() as $provider) {
            try {
                $location = $provider->locate($user, $request);
            } catch (Throwable $exception) {
                $this->container->make(ExceptionHandler::class)->report($exception);

                continue;
            }

            $location = $location instanceof Location
                ? $this->sanitize($location->withSource(ContextSource::Provider))
                : null;

            if ($location instanceof Location) {
                $this->store($user, $location);

                return $location;
            }
        }

        return null;
    }

    private function store(Model $user, ?Location $location): void
    {
        $previous = $this->location($user);

        $this->records->write($user, [
            'city' => $location?->city,
            'region' => $location?->region,
            'country_code' => $location?->countryCode,
            'country_name' => $location?->countryName,
            'location_source' => $location?->source,
        ]);

        if ($previous != $location) {
            LocationChanged::dispatch($user, $location);
        }
    }

    private function fromRecord(PresenceRecord $record): ?Location
    {
        $location = new Location(
            $record->city,
            $record->region,
            $record->country_code,
            $record->country_name,
            $record->location_source ?? ContextSource::User,
        );

        return $location->isEmpty() ? null : $location;
    }

    /**
     * Trim and bound every field and drop malformed values.
     */
    private function sanitize(Location $location): ?Location
    {
        $countryCode = $this->countryCode($location->countryCode);

        $location = new Location(
            $this->text($location->city),
            $this->text($location->region),
            $countryCode,
            $this->text($location->countryName) ?? $this->countryName($countryCode),
            $location->source,
        );

        return $location->isEmpty() ? null : $location;
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim(strip_tags($value));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }

    private function countryCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && ! in_array($code, self::NON_COUNTRIES, true)
            ? $code
            : null;
    }

    private function countryName(?string $countryCode): ?string
    {
        if ($countryCode === null || ! class_exists(Locale::class)) {
            return null;
        }

        $name = Locale::getDisplayRegion('-'.$countryCode, 'en');

        return is_string($name) && $name !== '' && $name !== $countryCode ? $name : null;
    }

    /**
     * @return iterable<LocationProvider>
     */
    private function providers(): iterable
    {
        foreach ([...$this->config->locationProviders(), ...$this->providers] as $provider) {
            $provider = is_string($provider) ? $this->container->make($provider) : $provider;

            if ($provider instanceof LocationProvider) {
                yield $provider;
            }
        }
    }
}
