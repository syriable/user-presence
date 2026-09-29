<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Casts\UtcDateTime;
use Syriable\UserPresence\Data\PresenceTimestamps;
use Syriable\UserPresence\Enums\PresenceStatus;
use Syriable\UserPresence\Events\UserCameOnline;
use Syriable\UserPresence\Events\UserWentOffline;
use Syriable\UserPresence\Exceptions\UnsupportedPresenceStore;
use Syriable\UserPresence\Models\PresenceRecord;
use Syriable\UserPresence\Storage\PresenceStoreManager;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;
use Syriable\UserPresence\Support\UserIdentifier;

/**
 * Heartbeats, online status and authentication activity.
 *
 * - last seen:     the latest presence signal of any kind (heartbeat, login, logout).
 * - last activity: the latest signal caused by the user interacting (a request,
 *                  or a browser heartbeat flagged as interactive).
 * - online:        last seen is younger than the expiration and the user has
 *                  not logged out since.
 */
final class PresenceService
{
    /** @var (Closure(Model): bool)|null */
    private ?Closure $heartbeatFilter = null;

    public function __construct(
        private readonly PresenceConfig $config,
        private readonly PresenceStoreManager $stores,
        private readonly PresenceRepository $records,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * Only record heartbeats for users accepted by the callback, for example to
     * ignore impersonated sessions. Pass null to remove the filter.
     *
     * @param  (Closure(Model): bool)|null  $filter
     */
    public function filterHeartbeatsUsing(?Closure $filter): void
    {
        $this->heartbeatFilter = $filter;
    }

    /**
     * Record that the user is present. Returns false when the heartbeat was
     * throttled, filtered or presence tracking is disabled.
     *
     * Pass $interactive = false for background signals (for example a timer
     * in an idle browser tab) that should keep the user online without
     * updating their last activity.
     */
    public function heartbeat(Model $user, bool $interactive = true): bool
    {
        if (! $this->config->heartbeatEnabled() || $user->getKey() === null) {
            return false;
        }

        if ($this->heartbeatFilter instanceof Closure && ! ($this->heartbeatFilter)($user)) {
            return false;
        }

        $recordSeen = $this->acquireThrottle($user, 'seen');
        $recordActivity = $interactive && $this->acquireThrottle($user, 'activity');

        if (! $recordSeen && ! $recordActivity) {
            return false;
        }

        $wasOnline = $this->isOnline($user);
        $now = $this->now();

        $this->stores->store()->touch($user, $now, $recordActivity ? $now : null);

        if (! $wasOnline) {
            UserCameOnline::dispatch($user);
        }

        return true;
    }

    public function recordLogin(Model $user, ?string $ipAddress = null): void
    {
        $now = $this->now();

        if ($this->config->authenticationEnabled()) {
            // Writing null when IP storage is disabled also clears an address
            // stored while the option was previously enabled.
            $this->records->write($user, [
                'last_login_at' => $now,
                'last_login_ip' => $this->config->storeIpAddress() ? $ipAddress : null,
            ]);
        }

        if (! $this->config->presenceEnabled()) {
            return;
        }

        $wasOnline = $this->isOnline($user);

        $this->stores->store()->touch($user, $now, $now);

        if (! $wasOnline) {
            UserCameOnline::dispatch($user);
        }
    }

    /**
     * Record a logout. The user is offline until their next heartbeat, which
     * may come from another device that is still signed in.
     */
    public function recordLogout(Model $user): void
    {
        if (! $this->config->authenticationEnabled()) {
            return;
        }

        $wasOnline = $this->isOnline($user);
        $now = $this->now();

        $this->records->write($user, ['last_logout_at' => $now]);

        if (! $this->config->presenceEnabled()) {
            return;
        }

        $this->stores->store()->touch($user, $now);
        $this->releaseThrottles($user);

        if ($wasOnline) {
            UserWentOffline::dispatch($user);
        }
    }

    public function status(Model $user): PresenceStatus
    {
        return $this->isOnline($user) ? PresenceStatus::Online : PresenceStatus::Offline;
    }

    public function isOnline(Model $user): bool
    {
        if (! $this->config->presenceEnabled()) {
            return false;
        }

        $lastSeenAt = $this->timestamps($user)->lastSeenAt;

        if (! $lastSeenAt instanceof CarbonImmutable || $lastSeenAt->lt($this->onlineThreshold())) {
            return false;
        }

        $lastLogoutAt = $this->lastLogoutAt($user);

        return ! $lastLogoutAt instanceof CarbonImmutable || $lastLogoutAt->lt($lastSeenAt);
    }

    public function lastSeenAt(Model $user): ?CarbonImmutable
    {
        return $this->timestamps($user)->lastSeenAt;
    }

    public function lastActivityAt(Model $user): ?CarbonImmutable
    {
        return $this->timestamps($user)->lastActivityAt;
    }

    public function lastLoginAt(Model $user): ?CarbonImmutable
    {
        return $this->config->authenticationEnabled()
            ? $this->records->find($user)?->last_login_at
            : null;
    }

    public function lastLogoutAt(Model $user): ?CarbonImmutable
    {
        return $this->config->authenticationEnabled()
            ? $this->records->find($user)?->last_logout_at
            : null;
    }

    /**
     * Constrain a query on the presence table to online users.
     *
     * @param  Builder<PresenceRecord>  $query
     * @return Builder<PresenceRecord>
     */
    public function constrainToOnline(Builder $query): Builder
    {
        $store = $this->config->presenceStore();

        if ($store !== 'database') {
            throw UnsupportedPresenceStore::notQueryable($store);
        }

        if (! $this->config->presenceEnabled()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('last_seen_at', '>=', UtcDateTime::toStorage($this->onlineThreshold()))
            ->where(static fn (Builder $query) => $query
                ->whereNull('last_logout_at')
                ->orWhereColumn('last_logout_at', '<', 'last_seen_at'));
    }

    public function forget(Model $user): void
    {
        $this->stores->store()->forget($user);
        $this->releaseThrottles($user);
    }

    private function timestamps(Model $user): PresenceTimestamps
    {
        if (! $this->config->presenceEnabled()) {
            return PresenceTimestamps::empty();
        }

        return $this->stores->store()->get($user);
    }

    private function onlineThreshold(): CarbonImmutable
    {
        return $this->now()->subSeconds($this->config->expiration());
    }

    private function acquireThrottle(Model $user, string $kind): bool
    {
        $interval = $this->config->heartbeatInterval();

        if ($interval === 0) {
            return true;
        }

        return $this->throttleCache()->add($this->throttleKey($user, $kind), true, $interval);
    }

    private function releaseThrottles(Model $user): void
    {
        $this->throttleCache()->forget($this->throttleKey($user, 'seen'));
        $this->throttleCache()->forget($this->throttleKey($user, 'activity'));
    }

    private function throttleKey(Model $user, string $kind): string
    {
        return "user-presence:throttle:{$kind}:".UserIdentifier::of($user);
    }

    private function throttleCache(): Cache
    {
        return $this->cache->store($this->config->cacheStore());
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
