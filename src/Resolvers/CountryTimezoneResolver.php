<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Resolvers;

use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\TimezoneResolver;
use Syriable\UserPresence\Services\LocationService;

/**
 * Derives a timezone from the user's stored country, but only when that
 * country has exactly one timezone (e.g. "SE" => "Europe/Stockholm").
 * Countries spanning several timezones (US, RU, BR, ...) are left alone
 * rather than guessed. Uses PHP's bundled timezone database only.
 */
final readonly class CountryTimezoneResolver implements TimezoneResolver
{
    public function __construct(private LocationService $locations) {}

    public function resolve(Model $user): ?string
    {
        $countryCode = $this->locations->location($user)?->countryCode;

        if ($countryCode === null) {
            return null;
        }

        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);

        return count($timezones) === 1 ? $timezones[0] : null;
    }
}
