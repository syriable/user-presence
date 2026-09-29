# Architecture

This document records the decisions behind `syriable/user-presence`, the alternatives that were considered, and the trade-offs that were accepted.

## Scope

The package collects, stores, normalizes and exposes **presence** (online status, last seen, last activity, login/logout) and **local context** (interface locale, spoken languages, timezone, optional city/country, small availability preferences). It deliberately does not render UI, broadcast in real time, authorize viewers or manage user profiles.

## Component overview

```text
Facade UserPresence ──► UserPresenceManager (public API, delegates only)
                              │
      ┌───────────────┬───────┴───────┬────────────────┬──────────────────┐
PresenceService  LocaleService  TimezoneService  LocationService  PreferenceService
      │                 │               │                │                 │
PresenceStoreManager    └───────────────┴────────┬───────┴─────────────────┘
  ├─ DatabasePresenceStore ──────────────────►  PresenceRepository (atomic upserts)
  ├─ CachePresenceStore                                  │
  └─ custom (extendStore)                          PresenceRecord (user_presences)

Entry points: RecordHeartbeat middleware, HeartbeatController, ApplyUserLocale
middleware, RecordAuthenticationActivity listener, HasUserPresence trait.
```

Each service owns one concern. The manager is a thin, stateless façade so the public API is discoverable in one place without becoming a god class. `PresenceConfig` gives typed, validated access to configuration and is read on every call so runtime changes (and tests) are honoured.

## Storage

**Decision:** one dedicated polymorphic table (`user_presences`), plus a configurable store for the high-frequency heartbeat timestamps.

| Option | Verdict |
|--------|---------|
| Columns on `users` | Rejected. Requires altering a table the package does not own, assumes its name, risks column collisions, fires model events and touches `updated_at` on every heartbeat, and supports only one user model. |
| Dedicated table | **Chosen.** Non-destructive, configurable name/model, supports several authenticatable models via `morphs`, eager-loadable, queryable. |
| Cache only | Offered as the `cache` presence store for heartbeat timestamps. Not suitable for stable preferences, which must survive cache flushes. |
| Hybrid | **Chosen.** Stable context (locale, timezone, location, login/logout, preferences) always lives in the table; heartbeat timestamps live in the table (default) or the cache. |

Writes use `upsert` keyed on `(presenceable_type, presenceable_id)` and update only the columns being changed. This avoids `firstOrCreate` races and prevents a heartbeat from overwriting a concurrent timezone change.

Datetimes are stored as `dateTime` (not `timestamp`, which MySQL converts with the session timezone) and always written and read as UTC through the `UtcDateTime` cast, independent of `app.timezone`.

Only the store abstraction for heartbeat timestamps is pluggable (`PresenceStore`: `get`, `touch`, `forget`). A generic storage abstraction for every field was rejected as unnecessary complexity: the stable context is small, rarely written, and well served by one table.

## Presence model

- **Heartbeat throttling** happens before the store via `Cache::add()` with the interval as TTL: an atomic "first writer wins" check supported by every Laravel cache driver. Separate throttle keys for "seen" and "activity" let a passive heartbeat keep a user online without blocking the next interactive one.
- **Online status is derived, never stored:** `last_seen_at >= now - expiration` and no logout at or after `last_seen_at`. Deriving it avoids a scheduler and stale flags. The cost is that expiry cannot emit an event.
- **Logout** writes `last_logout_at` and `last_seen_at` with the same instant and releases the throttle. The user is offline until any later heartbeat, which naturally supports multiple devices.
- **Seen vs activity** separates "the tab is open" from "the user did something", so applications can show "active 5 minutes ago" honestly.
- **Status enum** has only `online` and `offline`. Richer states (`away`, `busy`) have no reliable, general signal; applications can model them with preferences.

## Authentication activity

The package listens to `Login`, `Logout` and `CurrentDeviceLogout`. It never wraps guards or changes the authentication flow. `Login` does not fire on ordinary authenticated requests (only on explicit login or remember-me re-authentication), so logins are not over-counted. Guards are filtered by configuration so that, for example, an `admin` guard can be tracked or not.

Listeners are registered as `[class, method]` pairs so nothing is resolved during boot.

## Locale

- Interface locale and spoken languages are separate fields with separate validation: interface locales are validated against `locale.supported` (when set); spoken languages only need to be well-formed.
- Values are normalized to the configured spelling (`zh_cn` → `zh-CN`) so the same user never ends up with two spellings.
- **Precedence:** explicit (`user`) > detected (`browser`) > resolvers > default. The stored `locale_source` makes the precedence enforceable: detection never overwrites a `user` value.
- Accept-Language detection runs only when nothing is stored, so there is no per-request overwrite and at most one write.
- The package applies the locale through an opt-in middleware that calls `App::setLocale()`; it does not replace Laravel's translator.

## Timezone

- Only canonical IANA identifiers are accepted (validated against `DateTimeZone::listIdentifiers()`); offsets and abbreviations are rejected because they break across DST.
- **Precedence:** explicit > browser > resolvers > `timezone.default` (UTC). The server timezone is never used as a fallback.
- `CountryTimezoneResolver` only answers for single-timezone countries; guessing inside multi-timezone countries would be wrong more often than helpful.
- Time differences are computed from each zone's offset **at a given instant** using PHP's bundled tz database, so DST transitions and rule changes are handled. The `TimeDifference` value object documents that it must not be persisted.

## Location

- Disabled by default; when disabled, reads return `null` and writes are no-ops.
- Providers are an ordered chain; the first non-empty, sanitized answer wins. Exceptions are reported and skipped so a provider can never break a login.
- Every stored location carries its source; user-entered data is never replaced by inference.
- The only built-in provider reads CDN headers: free, local, no external calls. IP databases and HTTP services are left to application providers so the package has no dependency on a vendor that may change terms.
- No coordinates are modelled at all.

## Extensibility

| Extension | Mechanism |
|-----------|-----------|
| Presence store | `UserPresence::extendStore($name, $factory)` (Laravel `Manager`), selected by `presence.store`; built-in names can be replaced. |
| Timezone / locale resolvers | Config arrays + `registerTimezoneResolver()` / `registerLocaleResolver()`; class names are container-resolved. |
| Location providers | Config array + `registerLocationProvider()`. |
| Heartbeat behaviour | `filterHeartbeatsUsing()`, configurable middleware groups, the endpoint, or direct calls. |
| Storage model / table | `model` and `table_name` config. |
| Reactions | Events. |

A generic plugin system or dynamic attribute framework was intentionally not built. The preferences bag covers small application-specific values without new columns.

## Security boundaries

- The only write endpoint is authenticated and writes only `$request->user()`; there is no user identifier parameter.
- No read endpoint exists; exposure is an application decision guarded by Laravel gates/policies.
- Client-supplied timezone and locale are length-limited, validated and stored as `browser`-sourced hints.
- IP storage is opt-in and hidden from serialization; data is deleted with the user by default.

## Quality gates

- Pest feature, unit and architecture tests (Orchestra Testbench, SQLite).
- PHPStan level 9 with Larastan, no baseline entries.
- Laravel Pint and Rector (PHP 8.4 set, dead code, code quality, type declarations, early return).
