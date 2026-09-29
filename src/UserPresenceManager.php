<?php

declare(strict_types=1);

namespace Syriable\UserPresence;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Syriable\UserPresence\Contracts\LocaleResolver;
use Syriable\UserPresence\Contracts\LocationProvider;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Contracts\TimezoneResolver;
use Syriable\UserPresence\Data\Location;
use Syriable\UserPresence\Data\Presence;
use Syriable\UserPresence\Data\TimeDifference;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Enums\PresenceStatus;
use Syriable\UserPresence\Services\LocaleService;
use Syriable\UserPresence\Services\LocationService;
use Syriable\UserPresence\Services\PreferenceService;
use Syriable\UserPresence\Services\PresenceService;
use Syriable\UserPresence\Services\TimezoneService;
use Syriable\UserPresence\Storage\PresenceStoreManager;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * The public API of the package, available through the UserPresence facade.
 *
 * Every method accepts any Eloquent model (usually your User model). The
 * manager delegates to small focused services and holds no state itself.
 */
final readonly class UserPresenceManager
{
    public function __construct(
        private PresenceService $presence,
        private LocaleService $locales,
        private TimezoneService $timezones,
        private LocationService $locations,
        private PreferenceService $preferences,
        private PresenceStoreManager $stores,
        private PresenceRepository $records,
    ) {}

    // ---------------------------------------------------------------------
    // Presence
    // ---------------------------------------------------------------------

    /**
     * Record that the user is present. Returns false when throttled or disabled.
     */
    public function heartbeat(Model $user, bool $interactive = true): bool
    {
        return $this->presence->heartbeat($user, $interactive);
    }

    public function status(Model $user): PresenceStatus
    {
        return $this->presence->status($user);
    }

    public function isOnline(Model $user): bool
    {
        return $this->presence->isOnline($user);
    }

    public function isOffline(Model $user): bool
    {
        return ! $this->presence->isOnline($user);
    }

    public function lastSeenAt(Model $user): ?CarbonImmutable
    {
        return $this->presence->lastSeenAt($user);
    }

    public function lastActivityAt(Model $user): ?CarbonImmutable
    {
        return $this->presence->lastActivityAt($user);
    }

    // ---------------------------------------------------------------------
    // Authentication activity
    // ---------------------------------------------------------------------

    public function lastLoginAt(Model $user): ?CarbonImmutable
    {
        return $this->presence->lastLoginAt($user);
    }

    public function lastLogoutAt(Model $user): ?CarbonImmutable
    {
        return $this->presence->lastLogoutAt($user);
    }

    /**
     * Called automatically for Laravel's Login event. Call it yourself when
     * your application authenticates users without firing that event.
     */
    public function recordLogin(Model $user, ?string $ipAddress = null): void
    {
        $this->presence->recordLogin($user, $ipAddress);
    }

    /**
     * Called automatically for Laravel's Logout event.
     */
    public function recordLogout(Model $user): void
    {
        $this->presence->recordLogout($user);
    }

    // ---------------------------------------------------------------------
    // Locale
    // ---------------------------------------------------------------------

    /**
     * The user's interface locale, falling back to resolvers and the default.
     */
    public function locale(Model $user): string
    {
        return $this->locales->locale($user);
    }

    public function localeSource(Model $user): ?ContextSource
    {
        return $this->locales->source($user);
    }

    public function setLocale(Model $user, ?string $locale): void
    {
        $this->locales->setLocale($user, $locale);
    }

    /**
     * Remember a browser-reported locale unless the user chose one themselves.
     */
    public function recordDetectedLocale(Model $user, string $locale): bool
    {
        return $this->locales->recordDetected($user, $locale);
    }

    /**
     * Detect the locale from Accept-Language for users without a stored locale.
     */
    public function detectLocale(Model $user, Request $request): bool
    {
        return $this->locales->detectFromRequest($user, $request);
    }

    public function isSupportedLocale(string $locale): bool
    {
        return $this->locales->isSupported($locale);
    }

    /**
     * @return list<string>
     */
    public function spokenLanguages(Model $user): array
    {
        return $this->locales->spokenLanguages($user);
    }

    /**
     * @param  list<string>  $languages
     */
    public function setSpokenLanguages(Model $user, array $languages): void
    {
        $this->locales->setSpokenLanguages($user, $languages);
    }

    // ---------------------------------------------------------------------
    // Timezone and local time
    // ---------------------------------------------------------------------

    /**
     * The user's IANA timezone, falling back to resolvers and the default.
     */
    public function timezone(Model $user): string
    {
        return $this->timezones->timezone($user);
    }

    public function timezoneSource(Model $user): ?ContextSource
    {
        return $this->timezones->source($user);
    }

    public function setTimezone(Model $user, ?string $timezone): void
    {
        $this->timezones->setTimezone($user, $timezone);
    }

    /**
     * Remember a browser-reported timezone unless the user chose one themselves.
     */
    public function recordDetectedTimezone(Model $user, string $timezone): bool
    {
        return $this->timezones->recordDetected($user, $timezone);
    }

    public function isValidTimezone(string $timezone): bool
    {
        return TimezoneService::isValid($timezone);
    }

    /**
     * The user's current local time, or $at converted to the user's timezone.
     */
    public function localTime(Model $user, ?DateTimeInterface $at = null): CarbonImmutable
    {
        return $this->timezones->localTime($user, $at);
    }

    /**
     * How far $other's clock is ahead of (or behind) $user's clock right now
     * (or at $at), using current timezone and daylight saving rules.
     */
    public function timeDifference(Model $user, Model $other, ?DateTimeInterface $at = null): TimeDifference
    {
        return $this->timezones->difference($user, $other, $at);
    }

    // ---------------------------------------------------------------------
    // Location
    // ---------------------------------------------------------------------

    public function location(Model $user): ?Location
    {
        return $this->locations->location($user);
    }

    public function setLocation(Model $user, ?Location $location): void
    {
        $this->locations->setLocation($user, $location);
    }

    /**
     * Run the registered location providers for the request.
     */
    public function detectLocation(Model $user, Request $request): ?Location
    {
        return $this->locations->detect($user, $request);
    }

    public function city(Model $user): ?string
    {
        return $this->location($user)?->city;
    }

    public function countryCode(Model $user): ?string
    {
        return $this->location($user)?->countryCode;
    }

    public function countryName(Model $user): ?string
    {
        return $this->location($user)?->countryName;
    }

    // ---------------------------------------------------------------------
    // Preferences
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function preferences(Model $user): array
    {
        return $this->preferences->all($user);
    }

    public function preference(Model $user, string $key, mixed $default = null): mixed
    {
        return $this->preferences->get($user, $key, $default);
    }

    public function setPreference(Model $user, string $key, mixed $value): void
    {
        $this->preferences->set($user, $key, $value);
    }

    // ---------------------------------------------------------------------
    // Snapshot and lifecycle
    // ---------------------------------------------------------------------

    /**
     * Everything the package knows about the user, loaded with one query.
     */
    public function get(Model $user): Presence
    {
        return $this->records->remember($user, fn (): Presence => new Presence(
            status: $this->status($user),
            lastSeenAt: $this->lastSeenAt($user),
            lastActivityAt: $this->lastActivityAt($user),
            lastLoginAt: $this->lastLoginAt($user),
            lastLogoutAt: $this->lastLogoutAt($user),
            locale: $this->locale($user),
            spokenLanguages: $this->spokenLanguages($user),
            timezone: $this->timezone($user),
            localTime: $this->localTime($user),
            location: $this->location($user),
        ));
    }

    /**
     * Delete everything stored for the user.
     */
    public function forget(Model $user): void
    {
        $this->presence->forget($user);
        $this->records->delete($user);
    }

    // ---------------------------------------------------------------------
    // Extension points
    // ---------------------------------------------------------------------

    /**
     * Register (or replace) a presence store, selected with presence.store.
     *
     * @param  Closure(Container): PresenceStore  $factory
     */
    public function extendStore(string $name, Closure $factory): void
    {
        $this->stores->extend($name, $factory);
        $this->stores->flush();
    }

    public function store(): PresenceStore
    {
        return $this->stores->store();
    }

    /**
     * @param  class-string<TimezoneResolver>|TimezoneResolver  $resolver
     */
    public function registerTimezoneResolver(string|TimezoneResolver $resolver): void
    {
        $this->timezones->registerResolver($resolver);
    }

    /**
     * @param  class-string<LocaleResolver>|LocaleResolver  $resolver
     */
    public function registerLocaleResolver(string|LocaleResolver $resolver): void
    {
        $this->locales->registerResolver($resolver);
    }

    /**
     * @param  class-string<LocationProvider>|LocationProvider  $provider
     */
    public function registerLocationProvider(string|LocationProvider $provider): void
    {
        $this->locations->registerProvider($provider);
    }

    /**
     * Only record heartbeats for users accepted by the callback.
     *
     * @param  (Closure(Model): bool)|null  $filter
     */
    public function filterHeartbeatsUsing(?Closure $filter): void
    {
        $this->presence->filterHeartbeatsUsing($filter);
    }
}
