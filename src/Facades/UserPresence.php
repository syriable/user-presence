<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Facades;

use Illuminate\Support\Facades\Facade;
use Syriable\UserPresence\UserPresenceManager;

/**
 * @method static bool heartbeat(\Illuminate\Database\Eloquent\Model $user, bool $interactive = true)
 * @method static \Syriable\UserPresence\Enums\PresenceStatus status(\Illuminate\Database\Eloquent\Model $user)
 * @method static bool isOnline(\Illuminate\Database\Eloquent\Model $user)
 * @method static bool isOffline(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonImmutable|null lastSeenAt(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonImmutable|null lastActivityAt(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonImmutable|null lastLoginAt(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonImmutable|null lastLogoutAt(\Illuminate\Database\Eloquent\Model $user)
 * @method static void recordLogin(\Illuminate\Database\Eloquent\Model $user, string|null $ipAddress = null)
 * @method static void recordLogout(\Illuminate\Database\Eloquent\Model $user)
 * @method static string locale(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Syriable\UserPresence\Enums\ContextSource|null localeSource(\Illuminate\Database\Eloquent\Model $user)
 * @method static void setLocale(\Illuminate\Database\Eloquent\Model $user, string|null $locale)
 * @method static bool recordDetectedLocale(\Illuminate\Database\Eloquent\Model $user, string $locale)
 * @method static bool detectLocale(\Illuminate\Database\Eloquent\Model $user, \Illuminate\Http\Request $request)
 * @method static bool isSupportedLocale(string $locale)
 * @method static list<string> spokenLanguages(\Illuminate\Database\Eloquent\Model $user)
 * @method static void setSpokenLanguages(\Illuminate\Database\Eloquent\Model $user, list<string> $languages)
 * @method static string timezone(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Syriable\UserPresence\Enums\ContextSource|null timezoneSource(\Illuminate\Database\Eloquent\Model $user)
 * @method static void setTimezone(\Illuminate\Database\Eloquent\Model $user, string|null $timezone)
 * @method static bool recordDetectedTimezone(\Illuminate\Database\Eloquent\Model $user, string $timezone)
 * @method static bool isValidTimezone(string $timezone)
 * @method static \Carbon\CarbonImmutable localTime(\Illuminate\Database\Eloquent\Model $user, \DateTimeInterface|null $at = null)
 * @method static \Syriable\UserPresence\Data\TimeDifference timeDifference(\Illuminate\Database\Eloquent\Model $user, \Illuminate\Database\Eloquent\Model $other, \DateTimeInterface|null $at = null)
 * @method static \Syriable\UserPresence\Data\Location|null location(\Illuminate\Database\Eloquent\Model $user)
 * @method static void setLocation(\Illuminate\Database\Eloquent\Model $user, \Syriable\UserPresence\Data\Location|null $location)
 * @method static \Syriable\UserPresence\Data\Location|null detectLocation(\Illuminate\Database\Eloquent\Model $user, \Illuminate\Http\Request $request)
 * @method static string|null city(\Illuminate\Database\Eloquent\Model $user)
 * @method static string|null countryCode(\Illuminate\Database\Eloquent\Model $user)
 * @method static string|null countryName(\Illuminate\Database\Eloquent\Model $user)
 * @method static array<string, mixed> preferences(\Illuminate\Database\Eloquent\Model $user)
 * @method static mixed preference(\Illuminate\Database\Eloquent\Model $user, string $key, mixed $default = null)
 * @method static void setPreference(\Illuminate\Database\Eloquent\Model $user, string $key, mixed $value)
 * @method static \Syriable\UserPresence\Data\Presence get(\Illuminate\Database\Eloquent\Model $user)
 * @method static void forget(\Illuminate\Database\Eloquent\Model $user)
 * @method static void extendStore(string $name, \Closure $factory)
 * @method static \Syriable\UserPresence\Contracts\PresenceStore store()
 * @method static void registerTimezoneResolver(class-string<\Syriable\UserPresence\Contracts\TimezoneResolver>|\Syriable\UserPresence\Contracts\TimezoneResolver $resolver)
 * @method static void registerLocaleResolver(class-string<\Syriable\UserPresence\Contracts\LocaleResolver>|\Syriable\UserPresence\Contracts\LocaleResolver $resolver)
 * @method static void registerLocationProvider(class-string<\Syriable\UserPresence\Contracts\LocationProvider>|\Syriable\UserPresence\Contracts\LocationProvider $provider)
 * @method static void filterHeartbeatsUsing(\Closure|null $filter)
 *
 * @see UserPresenceManager
 */
final class UserPresence extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UserPresenceManager::class;
    }
}
