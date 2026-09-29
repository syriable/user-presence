# User Presence for Laravel

`syriable/user-presence` tracks **whether your users are online** and **their local context**: when they were last seen, when they logged in and out, their interface language, their timezone and local time, the time difference between two users, and (optionally) their city and country.

```php
use Syriable\UserPresence\Facades\UserPresence;

UserPresence::isOnline($user);                 // true
UserPresence::lastSeenAt($user);               // CarbonImmutable (UTC)
UserPresence::locale($user);                   // "ar"
UserPresence::timezone($user);                 // "Asia/Shanghai"
UserPresence::localTime($user);                // 2026-06-01 20:00 CST
UserPresence::timeDifference($me, $user)->format(); // "+06:00"
```

It is small and has no infrastructure requirements:

- No paid APIs, no SaaS, no WebSockets, no Redis, no frontend framework.
- Your `users` table is never modified. Data lives in one dedicated, polymorphic table.
- Works with plain Blade, Livewire, Inertia, Filament or any JavaScript frontend, without depending on any of them.
- Privacy-conscious defaults: location is off, IP addresses are not stored, browser hints never override a user's own choice.

It is **not** a chat package, an authentication package or a user-profile system. It only collects, stores, normalizes and exposes presence and local context.

## Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [User model integration](#user-model-integration)
4. [Concepts: seen, activity, login, logout, online](#concepts-seen-activity-login-logout-online)
5. [Presence tracking and heartbeats](#presence-tracking-and-heartbeats)
6. [Last login and logout](#last-login-and-logout)
7. [Locale management](#locale-management)
8. [Timezone management and local time](#timezone-management-and-local-time)
9. [Comparing local time between users](#comparing-local-time-between-users)
10. [City and country (optional)](#city-and-country-optional)
11. [Availability preferences](#availability-preferences)
12. [Storage drivers](#storage-drivers)
13. [Extending: resolvers, providers, stores](#extending-resolvers-providers-stores)
14. [Events](#events)
15. [Frontend integration](#frontend-integration)
16. [Privacy and security](#privacy-and-security)
17. [What is collected, and how](#what-is-collected-and-how)
18. [Configuration reference](#configuration-reference)
19. [Testing](#testing)
20. [Troubleshooting](#troubleshooting)
21. [Known limitations](#known-limitations)

## Requirements

| Package        | Version |
|----------------|---------|
| PHP            | 8.4+    |
| Laravel        | 13.x    |
| `ext-intl`     | optional, used to derive country names from ISO codes |

## Installation

```bash
composer require syriable/user-presence
php artisan user-presence:install
```

The install command publishes `config/user-presence.php` and the migration, then offers to run the migrations. You can also do it manually:

```bash
php artisan vendor:publish --tag="user-presence-config"
php artisan vendor:publish --tag="user-presence-migrations"
php artisan migrate
```

If your user models use UUID or ULID primary keys, either call `Schema::morphUsingUuids()` / `Schema::morphUsingUlids()` in a service provider before migrating, or replace `morphs()` with `uuidMorphs()` / `ulidMorphs()` in the published migration.

That is all that is needed for the defaults: every authenticated `web` request records a throttled heartbeat, and logins and logouts are recorded from Laravel's authentication events.

## User model integration

Add the `HasUserPresence` trait to any authenticatable model:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Syriable\UserPresence\Concerns\HasUserPresence;

class User extends Authenticatable
{
    use HasUserPresence;
}
```

The trait adds only:

| Member | Purpose |
|--------|---------|
| `presenceRecord()` | `MorphOne` relation to the stored record, for eager loading |
| `presence()` | A read-only `Presence` snapshot, loaded with a single query |
| `isOnline()` | Shortcut for `UserPresence::isOnline($this)` |
| `User::online()` | Query scope for online users (database store only) |

It also deletes the user's presence data when the model is deleted (force-deleted for soft-deleting models). Set `privacy.delete_with_user` to `false` to disable that.

The trait is optional. Every facade method accepts any Eloquent model, so you can track a model without it. Several user models (for example `User` and `Admin` on separate guards) are kept apart through the polymorphic key.

```php
$presence = $user->presence();

$presence->status;          // PresenceStatus::Online
$presence->lastSeenAt;      // ?CarbonImmutable
$presence->locale;          // "en"
$presence->timezone;        // "Europe/Berlin"
$presence->localTime;       // CarbonImmutable in the user's timezone
$presence->location;        // ?Location
$presence->toArray();
```

Avoid N+1 queries when listing users:

```php
$users = User::with('presenceRecord')->get();

foreach ($users as $user) {
    $user->isOnline(); // no extra query with the database store
}
```

## Concepts: seen, activity, login, logout, online

These five values answer different questions, so the package keeps them separate:

| Value | Meaning | Updated by |
|-------|---------|------------|
| `last_seen_at` | The latest presence signal of any kind. | Every recorded heartbeat, login and logout. |
| `last_activity_at` | The latest signal caused by the user doing something. | Requests (middleware) and heartbeats sent with `interactive: true`. |
| `last_login_at` | When the user last authenticated. | Laravel's `Login` event. Not updated by ordinary requests. |
| `last_logout_at` | When the user last logged out explicitly. | Laravel's `Logout` / `CurrentDeviceLogout` events. |
| online status | Calculated, never stored. | See below. |

A user is **online** when:

1. `last_seen_at` is younger than `presence.expiration` seconds, and
2. they have not logged out since `last_seen_at`.

Being online means "recently present", **not** "looking at the screen right now". Users close tabs, lose connectivity or put laptops to sleep without logging out, so offline status comes from the heartbeat expiring, not only from logout. A logout makes the user offline immediately, until the next heartbeat, which may come from another device that is still signed in.

## Presence tracking and heartbeats

A heartbeat is a small signal that the authenticated user is present:

```php
UserPresence::heartbeat($user);                      // interactive (updates activity too)
UserPresence::heartbeat($user, interactive: false);  // background signal (seen only)
```

`heartbeat()` returns `true` when it was recorded and `false` when it was throttled, filtered or disabled.

Heartbeats come from three places:

1. **The middleware** (automatic). `RecordHeartbeat` is appended to the middleware groups listed in `presence.heartbeat.middleware_groups` (default `['web']`). It covers classic page loads and Livewire updates, which go through the `web` group.
2. **The endpoint** `POST /user-presence/heartbeat` for JavaScript timers. See [Frontend integration](#frontend-integration).
3. **Your own code**, for example in a job, an API controller or a WebSocket handler.

**Throttling.** No matter how many requests a user makes, at most one write happens per user every `presence.heartbeat.interval` seconds. The throttle uses a cache lock (`Cache::add`), so the cache store must be shared between your servers.

```php
'presence' => [
    'enabled' => true,
    'expiration' => 180,        // online while last seen < 3 minutes ago
    'heartbeat' => [
        'enabled' => true,
        'interval' => 60,       // at most one write per user per minute
        'middleware_groups' => ['web'],  // [] disables automatic collection
    ],
],
```

Keep `expiration` comfortably larger than `interval` plus your JavaScript heartbeat period (for example `interval` 60 and `expiration` 180), otherwise active users can briefly appear offline.

**Filtering.** Skip heartbeats for certain users, for example during impersonation:

```php
// AppServiceProvider::boot()
UserPresence::filterHeartbeatsUsing(
    fn (Model $user): bool => ! session()->has('impersonator_id'),
);
```

**Using the middleware yourself.** If you disable automatic collection, you can still attach it to specific routes with the `user-presence.heartbeat` alias.

**Querying online users** (database store):

```php
User::online()->count();
User::online()->where('team_id', $team->id)->get();
```

## Last login and logout

Login and logout timestamps are recorded from Laravel's `Login`, `Logout` and `CurrentDeviceLogout` events, for the guards listed in `guards`. The authentication flow is never modified, and no timestamp is written on ordinary authenticated requests.

```php
UserPresence::lastLoginAt($user);   // ?CarbonImmutable, null if never logged in
UserPresence::lastLogoutAt($user);  // ?CarbonImmutable
```

Multiple guards:

```php
'guards' => ['web', 'admin'],
```

Token guards (for example Sanctum personal access tokens) do not fire `Login` events. If you authenticate users in a way that does not fire them, record the login yourself:

```php
UserPresence::recordLogin($user, $request->ip());
UserPresence::recordLogout($user);
```

All timestamps are stored and returned in **UTC**, independent of `app.timezone`. Convert them for display with `localTime()`.

## Locale management

The **interface locale** (the language of your UI) and **spoken languages** (the languages a person can talk in) are separate things and are stored separately.

```php
UserPresence::setLocale($user, 'ar');      // explicit choice
UserPresence::locale($user);               // "ar"
UserPresence::localeSource($user);         // ContextSource::User
UserPresence::setLocale($user, null);      // clear, fall back again

UserPresence::setSpokenLanguages($user, ['ar', 'en', 'sv']);
UserPresence::spokenLanguages($user);      // ["ar", "en", "sv"]
```

```php
'locale' => [
    'enabled' => true,
    'default' => null,                          // null = config('app.locale')
    'supported' => ['en', 'ar', 'fr', 'de', 'zh-CN'],
    'detect' => false,
    'resolvers' => [],
],
```

- Locales are standard language tags (`en`, `ar`, `de`, `zh-CN`). Input is normalized to the configured spelling: `zh_cn` becomes `zh-CN`.
- With a `supported` list, `setLocale()` throws `InvalidLocale` for anything else. With an empty list, any well-formed tag is accepted.
- **Resolution order:** stored value (explicit choice, then browser-detected), registered resolvers, then the default. A stored value that is no longer supported is skipped.

**Applying the locale to requests.** The package does not replace Laravel's localization. Add the `user-presence.locale` middleware after authentication to call `App::setLocale()` with the user's locale:

```php
Route::middleware(['auth', 'user-presence.locale'])->group(function () {
    // ...
});
```

**Browser detection** (opt-in, `locale.detect = true`, requires a `supported` list):

- The middleware reads `Accept-Language` **only when the user has no stored locale**, matches it against your supported locales (`de-AT` falls back to `de`), and remembers it with source `browser`.
- The heartbeat endpoint accepts a `locale` hint (`navigator.language`) with the same rules.
- A locale the user chose themselves is never overwritten by detection.

## Timezone management and local time

Timezones are stored as **IANA identifiers** (`Europe/Berlin`, `Asia/Shanghai`, `America/New_York`), never as fixed offsets like `+01:00`, because offsets change with daylight saving time.

```php
UserPresence::setTimezone($user, 'Asia/Shanghai');  // throws InvalidTimezone if invalid
UserPresence::timezone($user);                      // "Asia/Shanghai"
UserPresence::timezoneSource($user);                // ContextSource::User

UserPresence::localTime($user);                     // now, in the user's timezone
UserPresence::localTime($user, $order->created_at); // convert any timestamp
UserPresence::isValidTimezone('Europe/Stockholm');  // true
```

**Resolution order:**

1. The user's explicit choice (`setTimezone`).
2. The timezone reported by their browser (`recordDetectedTimezone`, or the heartbeat endpoint's `timezone` field).
3. Registered resolvers, in order. The default `CountryTimezoneResolver` uses the stored country when it has exactly one timezone (Sweden → `Europe/Stockholm`), and leaves multi-timezone countries such as the US alone.
4. `timezone.default` (`UTC`).

The server's timezone is never assumed to be the user's. An invalid stored or resolved value falls through to the next step.

A validation rule for your settings form:

```php
use Syriable\UserPresence\Facades\UserPresence;

$request->validate([
    'timezone' => ['required', 'string', function ($attribute, $value, $fail) {
        if (! UserPresence::isValidTimezone($value)) {
            $fail('Please choose a valid timezone.');
        }
    }],
]);

UserPresence::setTimezone($request->user(), $request->input('timezone'));
```

## Comparing local time between users

```php
$difference = UserPresence::timeDifference($me, $colleague);

$difference->seconds;      // 21600
$difference->inHours();    // 6.0
$difference->inMinutes();  // 360
$difference->format();     // "+06:00"
$difference->isAhead();    // their clock is ahead of mine
$difference->isBehind();
$difference->isSame();
```

The difference is calculated from **current** timezone rules at a given instant (now by default), so it is correct across daylight saving changes. Berlin and New York are usually 6 hours apart, but only 5 hours for a few weeks each spring and autumn:

```php
UserPresence::timeDifference($berlin, $newYork, CarbonImmutable::parse('2026-03-15'))->format(); // "-05:00"
UserPresence::timeDifference($berlin, $newYork, CarbonImmutable::parse('2026-04-15'))->format(); // "-06:00"
```

Do not store the result as a permanent difference between two users.

Showing another user's local time in Blade:

```blade
@can('viewLocalTime', $member)
    <span>
        {{ UserPresence::localTime($member)->format('H:i') }} local time
        ({{ UserPresence::timeDifference(auth()->user(), $member)->format() }})
    </span>
@endcan
```

## City and country (optional)

Location support is **disabled by default**. When disabled, nothing is collected, stored or returned.

```php
'location' => [
    'enabled' => true,
    'detect_on_login' => true,
    'providers' => [
        \Syriable\UserPresence\Location\HeaderLocationProvider::class,
    ],
],
```

A location entered by the user:

```php
use Syriable\UserPresence\Data\Location;

UserPresence::setLocation($user, new Location(city: 'Stockholm', countryCode: 'SE'));

UserPresence::location($user);      // Location { city: "Stockholm", countryCode: "SE", countryName: "Sweden", source: User }
UserPresence::city($user);          // "Stockholm"
UserPresence::countryCode($user);   // "SE"
UserPresence::countryName($user);   // "Sweden" (derived with ext-intl when not provided)
UserPresence::setLocation($user, null); // clear
```

**Detected locations.** When a user logs in (and `detect_on_login` is true), the configured providers are asked in order; the first usable answer is stored with source `provider`. You can also run detection yourself with `UserPresence::detectLocation($user, $request)`.

- A location the user entered is never replaced by a detected one. `$location->isInferred()` tells you which kind you have.
- Provider output is sanitized: country codes must be ISO 3166-1 alpha-2 (`XX`/`T1` are discarded), text is trimmed, stripped of tags and limited to 120 characters.
- A provider that throws is reported to your exception handler and skipped, so it can never break a login.

**Free, local sources:**

- `HeaderLocationProvider` reads the visitor headers set by Cloudflare (`CF-IPCountry`, and `CF-IPCity`/`CF-Region` with the "visitor location headers" managed transform) or CloudFront (`CloudFront-Viewer-Country`, `-City`, `-Country-Region-Name`). Header names are configurable under `location.headers`. **Only enable it when such a proxy sits in front of your application and sets these headers itself.** Otherwise clients can send fake headers.
- A self-hosted database such as MaxMind GeoLite2 or DB-IP Lite, through a custom provider (see below).
- Any HTTP geolocation service, through a custom provider. No such service is built in, because free tiers change or disappear.

**Limitations of IP-based location.** IP geolocation is an estimate. VPNs, mobile carriers, corporate proxies and privacy relays routinely place users in the wrong city or country. A detected city is where a request appeared to come from, not where the user lives. Treat it as a hint, prefer user-provided values, and do not show inferred locations to other users as fact.

## Availability preferences

A small key/value store for application-defined preferences, such as "hide my online status" or working hours. The package stores them and does not interpret them.

```php
UserPresence::setPreference($user, 'show_online_status', false);
UserPresence::setPreference($user, 'working_hours', ['start' => '09:00', 'end' => '17:00']);

UserPresence::preference($user, 'show_online_status', true); // false
UserPresence::preferences($user);                            // all
UserPresence::setPreference($user, 'working_hours', null);   // remove
```

Keys may contain letters, digits, `_`, `.` and `-` (max 64 characters). Values must be scalars or arrays.

## Storage drivers

Everything except the heartbeat timestamps lives in the `user_presences` table. Where the frequently written heartbeat timestamps live is configurable with `presence.store`:

| Store | Heartbeat writes | Durable | `User::online()` | Best for |
|-------|------------------|---------|------------------|----------|
| `database` (default) | One throttled upsert per user per interval | Yes | Yes | Most applications. "Last seen" survives restarts and deploys. |
| `cache` | Cache only, no database writes | No: lost on `cache:clear`, eviction or TTL | No | High-traffic applications that only need live online indicators. |
| custom | Your choice | Your choice | No | Special infrastructure (see below). |

```php
'presence' => [
    'store' => env('USER_PRESENCE_STORE', 'database'),
    'cache' => [
        'store' => env('USER_PRESENCE_CACHE_STORE'), // null = default cache store
        'ttl' => 60 * 60 * 24 * 30,                  // cache store only; null = forever
    ],
],
```

**Why a dedicated table instead of columns on `users`?** Adding columns to `users` would mean changing a table the package does not own, colliding with existing column names, assuming the table is named `users`, and touching `users.updated_at` and model events on every heartbeat. A separate polymorphic table avoids all of that, supports several user models, and keeps frequently written presence data away from your main user rows. Writes are single atomic upserts on `(presenceable_type, presenceable_id)` that only touch the columns being changed, so concurrent heartbeats and preference updates do not overwrite each other.

**Custom model or table name:**

```php
'model' => App\Models\UserPresence::class,   // must extend Syriable\UserPresence\Models\PresenceRecord
'table_name' => 'member_presences',
```

## Extending: resolvers, providers, stores

Register extensions in a service provider's `boot()` method, or list classes in the configuration. Everything is resolved from the container, so constructor injection works.

**Timezone resolver**, for example from a company office:

```php
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Contracts\TimezoneResolver;

final class OfficeTimezoneResolver implements TimezoneResolver
{
    public function resolve(Model $user): ?string
    {
        return $user->office?->timezone; // null lets the next resolver decide
    }
}

UserPresence::registerTimezoneResolver(OfficeTimezoneResolver::class);
// or: 'timezone' => ['resolvers' => [OfficeTimezoneResolver::class, CountryTimezoneResolver::class]]
```

**Locale resolver**, for example from a tenant:

```php
use Syriable\UserPresence\Contracts\LocaleResolver;

final class TenantLocaleResolver implements LocaleResolver
{
    public function resolve(Model $user): ?string
    {
        return $user->tenant?->default_locale;
    }
}

UserPresence::registerLocaleResolver(TenantLocaleResolver::class);
```

Resolvers are consulted only when nothing is stored for the user, and their results are validated (invalid timezones and unsupported locales are ignored). Configured resolvers run before runtime-registered ones; to replace the defaults, change the configuration arrays.

**Location provider** backed by a self-hosted MaxMind GeoLite2 database (using `geoip2/geoip2`, installed by your application):

```php
use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Syriable\UserPresence\Contracts\LocationProvider;
use Syriable\UserPresence\Data\Location;

final class GeoLiteLocationProvider implements LocationProvider
{
    public function __construct(private Reader $reader) {}

    public function locate(Model $user, Request $request): ?Location
    {
        $record = $this->reader->city($request->ip());

        return new Location(
            city: $record->city->name,
            region: $record->mostSpecificSubdivision->name,
            countryCode: $record->country->isoCode,
            countryName: $record->country->name,
        );
    }
}

UserPresence::registerLocationProvider(GeoLiteLocationProvider::class);
```

The IP address is only used to look up the location. It is not stored unless `privacy.store_ip_address` is enabled.

**Presence store:**

```php
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Data\PresenceTimestamps;

final class RedisHashPresenceStore implements PresenceStore
{
    public function get(Model $user): PresenceTimestamps { /* ... */ }

    public function touch(Model $user, CarbonImmutable $seenAt, ?CarbonImmutable $activityAt = null): void { /* ... */ }

    public function forget(Model $user): void { /* ... */ }
}

UserPresence::extendStore('redis-hash', fn (Container $app) => new RedisHashPresenceStore(/* ... */));
```

```dotenv
USER_PRESENCE_STORE=redis-hash
```

A store only persists timestamps; throttling, online calculation and events stay in the package. `extendStore()` can also replace the built-in `database` and `cache` stores.

## Events

| Event | Dispatched when |
|-------|-----------------|
| `UserCameOnline` | A recorded heartbeat or login finds the user offline. |
| `UserWentOffline` | An online user logs out. |
| `LocaleChanged` | The stored locale changes (`locale`, `previousLocale`, `source`). |
| `TimezoneChanged` | The stored timezone changes. |
| `LocationChanged` | The stored location changes. |

Expiry is calculated when status is read, not by a background process, so **no event is dispatched when a heartbeat simply expires**. If you need that, run a scheduled command that compares `User::online()` snapshots.

```php
Event::listen(UserCameOnline::class, function (UserCameOnline $event) {
    // $event->user
});
```

## Frontend integration

The package is frontend-agnostic. It gives you the data; you decide how to show it.

**Sending a heartbeat from the browser.** The endpoint uses the `web` and `auth` middleware, so it needs the session cookie and a CSRF token:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">

<script>
(() => {
    const url = @json(route('user-presence.heartbeat'));
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let interactedAt = Date.now();

    ['keydown', 'pointerdown', 'scroll'].forEach((type) =>
        addEventListener(type, () => (interactedAt = Date.now()), { passive: true }));

    const beat = () => {
        if (document.visibilityState !== 'visible') return;

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({
                interactive: Date.now() - interactedAt < 60_000,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                locale: navigator.language,
            }),
        }).catch(() => {});
    };

    beat();
    setInterval(beat, 60_000);
    document.addEventListener('visibilitychange', beat);
})();
</script>
```

Request fields, all optional:

| Field | Type | Effect |
|-------|------|--------|
| `interactive` | boolean, default `true` | `false` updates last seen only. |
| `timezone` | IANA identifier | Remembered unless the user chose a timezone. Invalid values are ignored. |
| `locale` | language tag | Remembered when `locale.detect` is on and the user has not chosen one. |

Response: `{"status": "online", "interval": 60}`.

With Axios (as configured in Laravel's starter kits) the CSRF header is sent for you:

```js
axios.post('/user-presence/heartbeat', { timezone: Intl.DateTimeFormat().resolvedOptions().timeZone });
```

**Livewire.** Livewire requests go through the `web` group, so users interacting with Livewire components are tracked automatically. For pages that stay open without interaction, add a poll to any component on the page; each poll is a `web` request and records a heartbeat:

```blade
<div wire:poll.60s>
    {{-- component markup --}}
</div>
```

**Online indicator and last-seen label (Blade):**

```blade
@if (UserPresence::isOnline($member))
    <span class="text-green-600">● Online</span>
@elseif ($seen = UserPresence::lastSeenAt($member))
    <span>Last seen {{ $seen->diffForHumans() }}</span>
@endif
```

**Updating the locale from a settings form:**

```php
public function update(Request $request)
{
    $request->validate(['locale' => ['required', 'string']]);

    try {
        UserPresence::setLocale($request->user(), $request->input('locale'));
    } catch (InvalidLocale) {
        return back()->withErrors(['locale' => 'This language is not available.']);
    }

    return back();
}
```

**Exposing presence through your own API** (authorize first; see below):

```php
Route::get('/members/{member}/presence', function (User $member) {
    Gate::authorize('viewPresence', $member);

    return [
        'status' => UserPresence::status($member),
        'last_seen_at' => UserPresence::lastSeenAt($member),
        'local_time' => UserPresence::localTime($member)->toIso8601String(),
    ];
})->middleware('auth');
```

## Privacy and security

- **Only your own presence can be written from the browser.** The heartbeat endpoint requires authentication and only ever updates `$request->user()`; there is no parameter to target another user.
- **Nothing is exposed publicly.** The package registers no endpoint that reads presence data. What other users can see is decided by your application. Use Laravel's gates and policies before showing last-seen times, local time or location:

  ```php
  Gate::define('viewPresence', fn (User $viewer, User $member) =>
      $viewer->team_id === $member->team_id
      && UserPresence::preference($member, 'show_online_status', true));
  ```

- **Client values are hints, not identity.** Browser timezones and locales are validated, stored with source `browser`, and never override a user's own choice.
- **Location is off by default**, never includes coordinates, distinguishes user-entered from inferred values, and can be disabled completely.
- **IP addresses are not stored** unless `privacy.store_ip_address` is `true`. Even then, only the most recent login IP is kept, and it is hidden from the model's array/JSON form.
- **Data is removed with the user** (`privacy.delete_with_user`), or on demand with `UserPresence::forget($user)`, which helps with erasure requests.
- **Everything can be switched off** per area: `presence.enabled`, `presence.heartbeat.enabled`, `authentication.enabled`, `locale.enabled`, `timezone.enabled`, `location.enabled`, `routes.enabled`.

## What is collected, and how

| Data | Collected automatically | Needs frontend support | Optional / off by default |
|------|:----:|:----:|:----:|
| Last seen, last activity | Yes, via middleware on `web` requests | Only for tabs left open without requests (JS heartbeat) | |
| Online status | Calculated | | |
| Last login / logout | Yes, via authentication events | | |
| Interface locale | Only the user's explicit choice | `navigator.language` hint | Browser detection is off |
| Spoken languages | No, set by your application | | |
| Timezone | No | `Intl` timezone hint via heartbeat | |
| City / country | No | | Off; needs a provider |
| Login IP address | No | | Off |
| Preferences | No, set by your application | | |

## Configuration reference

| Key | Default | Description |
|-----|---------|-------------|
| `model` | `PresenceRecord::class` | Eloquent model, must extend the package model. |
| `table_name` | `user_presences` | Table name. |
| `guards` | `['web']` | Guards tracked by the middleware and authentication listeners. |
| `presence.enabled` | `true` | Master switch for presence. |
| `presence.store` | `database` | `database`, `cache` or a custom store name. |
| `presence.expiration` | `180` | Seconds a heartbeat keeps the user online. |
| `presence.heartbeat.enabled` | `true` | Record heartbeats at all. |
| `presence.heartbeat.interval` | `60` | Minimum seconds between writes per user. `0` disables throttling. |
| `presence.heartbeat.middleware_groups` | `['web']` | Groups that get the heartbeat middleware. `[]` to disable. |
| `presence.cache.store` | `null` | Cache store for the throttle and the `cache` presence store. |
| `presence.cache.ttl` | 30 days | Lifetime of entries in the `cache` presence store. |
| `authentication.enabled` | `true` | Record login and logout timestamps. |
| `locale.enabled` | `true` | Locale management. |
| `locale.default` | `null` | Fallback locale, `null` = `app.locale`. |
| `locale.supported` | `[]` | Allowed interface locales. Empty = any well-formed tag. |
| `locale.detect` | `false` | Remember browser locales for users without a choice. |
| `locale.resolvers` | `[]` | `LocaleResolver` classes. |
| `timezone.enabled` | `true` | Timezone management. |
| `timezone.default` | `UTC` | Fallback timezone. |
| `timezone.detect` | `true` | Accept browser timezones from the heartbeat endpoint. |
| `timezone.resolvers` | `[CountryTimezoneResolver::class]` | `TimezoneResolver` classes. |
| `location.enabled` | `false` | Location support. |
| `location.detect_on_login` | `true` | Run providers when a user logs in. |
| `location.providers` | `[]` | `LocationProvider` classes. |
| `location.headers` | Cloudflare / CloudFront | Header names for `HeaderLocationProvider`. |
| `privacy.store_ip_address` | `false` | Store the last login IP. |
| `privacy.delete_with_user` | `true` | Delete presence data with the user model. |
| `routes.enabled` | `true` | Register the heartbeat endpoint. |
| `routes.prefix` | `user-presence` | Endpoint prefix. |
| `routes.middleware` | `['web', 'auth']` | Endpoint middleware, e.g. `['web', 'auth:web,admin']`. |

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan (level 9, Larastan)
composer format     # Laravel Pint
composer refactor   # Rector
```

In your own application tests, use Laravel's time helpers to test expiry:

```php
UserPresence::heartbeat($user);

$this->travel(4)->minutes();

expect(UserPresence::isOnline($user))->toBeFalse();
```

Set `presence.heartbeat.interval` to `0` in tests where you need every heartbeat recorded.

## Troubleshooting

**Users never appear online.**
Check that the user's guard is listed in `guards`, that `presence.heartbeat.middleware_groups` contains the group your routes use (API routes use `api`, not `web`), and that the migration has run.

**Heartbeats are written on every request.**
The throttle needs a cache that persists between requests. The `array` cache store does not. Use `redis`, `memcached`, `database` or `file`.

**Users flicker between online and offline.**
`presence.expiration` is too close to `presence.heartbeat.interval` or to your JavaScript heartbeat period. Use at least `interval + client period + a margin`.

**The heartbeat endpoint returns 419.**
The CSRF token is missing. Send the `X-CSRF-TOKEN` header (or `X-XSRF-TOKEN` from the cookie, as Axios does).

**The heartbeat endpoint returns 401 for my admin guard.**
Change `routes.middleware` to `['web', 'auth:web,admin']`.

**`User::online()` throws `UnsupportedPresenceStore`.**
Online users can only be queried with the `database` store. With the `cache` store, check individual users with `isOnline()`.

**Timezones are always UTC.**
Nothing is known about the user yet. Let them choose a timezone, send the browser timezone with the heartbeat, or register a resolver.

**The detected locale is ignored.**
Set `locale.detect` to `true` and fill `locale.supported`. A locale the user already chose is never replaced.

**Country names are missing.**
Install the `intl` PHP extension, or have your provider return `countryName`.

**`morphs()` fails for UUID users.**
See the note on UUID/ULID keys under [Installation](#installation).

## Known limitations

- Online status is only as fresh as the latest heartbeat. Without a JavaScript heartbeat, a user reading a single page for longer than `expiration` appears offline.
- No event fires when a heartbeat expires; offline-by-expiry is calculated on read.
- The `cache` store loses history when the cache is cleared and cannot be queried with SQL.
- IP- and header-based locations are estimates and can be wrong or spoofed if the proxy is not trusted.
- Only the most recent login is kept, not a login history.
- Preferences use read-modify-write; two simultaneous updates to different keys of the same user can race.

See [docs/architecture.md](docs/architecture.md) for the architectural decisions behind the package.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
