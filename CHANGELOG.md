# Changelog

All notable changes to `user-presence` will be documented in this file.

## Unreleased

Initial release.

- Heartbeat-based online / offline status with configurable throttling and expiration.
- Last seen, last activity, last login and last logout timestamps, stored in UTC.
- Automatic heartbeat middleware, an authenticated heartbeat endpoint, and a locale middleware.
- Database and cache presence stores, plus custom stores via `UserPresence::extendStore()`.
- Interface locale management with supported-locale validation, normalization, optional browser detection and custom resolvers.
- Spoken languages, kept separate from the interface locale.
- IANA timezone management, local time conversion and DST-aware time differences between users.
- Optional, privacy-conscious city / country support with pluggable providers and a CDN header provider.
- Application-defined availability preferences.
- `HasUserPresence` trait with eager-loadable relation, snapshot and `online()` scope.
- Events: `UserCameOnline`, `UserWentOffline`, `LocaleChanged`, `TimezoneChanged`, `LocationChanged`.
