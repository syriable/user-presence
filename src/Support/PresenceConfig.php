<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Syriable\UserPresence\Models\PresenceRecord;

/**
 * Typed, validated access to the package configuration.
 *
 * Values are read on every call so runtime configuration changes are honoured.
 */
final readonly class PresenceConfig
{
    public function __construct(private Repository $config) {}

    /**
     * @return class-string<PresenceRecord>
     */
    public function model(): string
    {
        $model = $this->string('model', PresenceRecord::class);

        if (! is_a($model, PresenceRecord::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'The configured user-presence model [%s] must extend [%s].',
                $model,
                PresenceRecord::class,
            ));
        }

        return $model;
    }

    public function tableName(): string
    {
        return $this->string('table_name', 'user_presences');
    }

    /**
     * @return list<string>
     */
    public function guards(): array
    {
        return $this->stringList('guards');
    }

    public function tracksGuard(?string $guard): bool
    {
        return $guard !== null && in_array($guard, $this->guards(), true);
    }

    public function presenceEnabled(): bool
    {
        return $this->bool('presence.enabled', true);
    }

    public function presenceStore(): string
    {
        return $this->string('presence.store', 'database');
    }

    public function expiration(): int
    {
        return max(1, $this->int('presence.expiration', 180));
    }

    public function heartbeatEnabled(): bool
    {
        return $this->presenceEnabled() && $this->bool('presence.heartbeat.enabled', true);
    }

    public function heartbeatInterval(): int
    {
        return max(0, $this->int('presence.heartbeat.interval', 60));
    }

    /**
     * @return list<string>
     */
    public function heartbeatMiddlewareGroups(): array
    {
        return $this->stringList('presence.heartbeat.middleware_groups');
    }

    public function cacheStore(): ?string
    {
        return $this->nullableString('presence.cache.store');
    }

    public function cacheTtl(): ?int
    {
        $ttl = $this->config->get('user-presence.presence.cache.ttl');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : null;
    }

    public function authenticationEnabled(): bool
    {
        return $this->bool('authentication.enabled', true);
    }

    public function localeEnabled(): bool
    {
        return $this->bool('locale.enabled', true);
    }

    public function defaultLocale(): string
    {
        $default = $this->nullableString('locale.default')
            ?? $this->config->get('app.locale');

        return is_string($default) && $default !== '' ? $default : 'en';
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        return $this->stringList('locale.supported');
    }

    public function detectLocale(): bool
    {
        return $this->bool('locale.detect', false);
    }

    /**
     * @return list<string>
     */
    public function localeResolvers(): array
    {
        return $this->stringList('locale.resolvers');
    }

    public function timezoneEnabled(): bool
    {
        return $this->bool('timezone.enabled', true);
    }

    public function defaultTimezone(): string
    {
        return $this->string('timezone.default', 'UTC');
    }

    public function detectTimezone(): bool
    {
        return $this->bool('timezone.detect', true);
    }

    /**
     * @return list<string>
     */
    public function timezoneResolvers(): array
    {
        return $this->stringList('timezone.resolvers');
    }

    public function locationEnabled(): bool
    {
        return $this->bool('location.enabled', false);
    }

    public function detectLocationOnLogin(): bool
    {
        return $this->bool('location.detect_on_login', true);
    }

    /**
     * @return list<string>
     */
    public function locationProviders(): array
    {
        return $this->stringList('location.providers');
    }

    /**
     * @return list<string>
     */
    public function locationHeaders(string $field): array
    {
        return $this->stringList("location.headers.{$field}");
    }

    public function storeIpAddress(): bool
    {
        return $this->bool('privacy.store_ip_address', false);
    }

    public function deleteWithUser(): bool
    {
        return $this->bool('privacy.delete_with_user', true);
    }

    public function routesEnabled(): bool
    {
        return $this->bool('routes.enabled', true);
    }

    public function routePrefix(): string
    {
        return $this->string('routes.prefix', 'user-presence');
    }

    /**
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        return $this->stringList('routes.middleware');
    }

    private function bool(string $key, bool $default): bool
    {
        return (bool) $this->config->get("user-presence.{$key}", $default);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get("user-presence.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config->get("user-presence.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get("user-presence.{$key}", []);

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
