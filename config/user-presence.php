<?php

declare(strict_types=1);

use Syriable\UserPresence\Models\PresenceRecord;
use Syriable\UserPresence\Resolvers\CountryTimezoneResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Storage model
    |--------------------------------------------------------------------------
    |
    | Presence and local context live in a dedicated, polymorphic table so the
    | application's users table is never modified. Any Eloquent model can be
    | tracked, which also covers applications with several user models.
    |
    | You may extend the model to add behaviour. It must extend the package
    | model so that the casts and the table name keep working.
    |
    */

    'model' => PresenceRecord::class,

    'table_name' => 'user_presences',

    /*
    |--------------------------------------------------------------------------
    | Authentication guards
    |--------------------------------------------------------------------------
    |
    | Only users authenticated through these guards are tracked by the
    | automatic heartbeat middleware and the login / logout listeners.
    |
    */

    'guards' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | A user is online while their last heartbeat is younger than the
    | "expiration" (in seconds) and they have not logged out since.
    |
    | "store" decides where the frequently written heartbeat timestamps live:
    |
    |  - "database": the package table. Durable and queryable (User::online()).
    |  - "cache":    any Laravel cache store. No database writes for
    |                heartbeats, but data is lost when the cache is flushed.
    |  - any name registered with UserPresence::extendStore().
    |
    | Heartbeats are throttled: at most one write per user every "interval"
    | seconds, no matter how many requests the user makes. The throttle uses
    | the cache store configured below, so it must be shared between servers
    | (for example redis, memcached or database; not "array").
    |
    */

    'presence' => [
        'enabled' => true,

        'store' => env('USER_PRESENCE_STORE', 'database'),

        'expiration' => 180,

        'heartbeat' => [
            'enabled' => true,

            'interval' => 60,

            // Middleware groups that record a heartbeat on every request.
            // Use an empty array to disable automatic collection and only
            // record heartbeats from the endpoint or your own code.
            'middleware_groups' => ['web'],
        ],

        'cache' => [
            // null uses the application's default cache store.
            'store' => env('USER_PRESENCE_CACHE_STORE'),

            // How long (in seconds) the "cache" store keeps timestamps.
            // null keeps them until the cache is flushed.
            'ttl' => 60 * 60 * 24 * 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication activity
    |--------------------------------------------------------------------------
    |
    | Records last_login_at and last_logout_at from Laravel's authentication
    | events. The authentication flow itself is never modified.
    |
    */

    'authentication' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interface locale
    |--------------------------------------------------------------------------
    |
    | "default": used when nothing else is known. null means config('app.locale').
    | "supported": the interface locales your application ships. When empty,
    |              any well-formed language tag is accepted.
    | "detect":    remember the browser's Accept-Language / navigator.language
    |              when the user has not chosen a locale. Requires a
    |              non-empty "supported" list. Never overrides a saved choice.
    | "resolvers": classes implementing Contracts\LocaleResolver, consulted in
    |              order when the user has no stored locale.
    |
    */

    'locale' => [
        'enabled' => true,

        'default' => null,

        'supported' => [],

        'detect' => false,

        'resolvers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Timezones are always IANA identifiers such as "Europe/Berlin", never
    | fixed offsets. Resolution order: the user's explicit choice, the
    | browser-reported timezone, the resolvers below, then "default".
    |
    */

    'timezone' => [
        'enabled' => true,

        'default' => 'UTC',

        // Accept timezones reported by the browser (heartbeat endpoint).
        'detect' => true,

        'resolvers' => [
            // Uses the stored country when it has exactly one timezone.
            CountryTimezoneResolver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Location (city / country)
    |--------------------------------------------------------------------------
    |
    | Disabled by default. When disabled nothing is collected, stored or
    | returned. Precise coordinates are never collected.
    |
    | "providers" are classes implementing Contracts\LocationProvider, tried
    | in order when a user logs in (if "detect_on_login" is true). A value the
    | user entered themselves is never replaced by a detected one.
    |
    | HeaderLocationProvider reads country / city headers set by a CDN or
    | reverse proxy such as Cloudflare or CloudFront. Only enable it when
    | such a proxy sits in front of the application and overwrites these
    | headers, otherwise clients can spoof them.
    |
    */

    'location' => [
        'enabled' => false,

        'detect_on_login' => true,

        'providers' => [
            // \Syriable\UserPresence\Location\HeaderLocationProvider::class,
        ],

        'headers' => [
            'country' => ['CF-IPCountry', 'CloudFront-Viewer-Country'],
            'region' => ['CF-Region', 'CloudFront-Viewer-Country-Region-Name'],
            'city' => ['CF-IPCity', 'CloudFront-Viewer-City'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    */

    'privacy' => [
        // Store the IP address used for the most recent login.
        'store_ip_address' => false,

        // Delete a user's presence record when the user model is deleted
        // (force-deleted for soft-deleting models).
        'delete_with_user' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat endpoint
    |--------------------------------------------------------------------------
    |
    | POST {prefix}/heartbeat, named "user-presence.heartbeat". The endpoint
    | only ever updates the authenticated user's own presence.
    |
    */

    'routes' => [
        'enabled' => true,

        'prefix' => 'user-presence',

        'middleware' => ['web', 'auth'],
    ],

];
