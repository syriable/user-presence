<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Syriable\UserPresence\Contracts\PresenceStore;
use Syriable\UserPresence\Data\PresenceTimestamps;
use Syriable\UserPresence\Exceptions\UnsupportedPresenceStore;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Storage\CachePresenceStore;
use Syriable\UserPresence\Storage\DatabasePresenceStore;
use Syriable\UserPresence\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->travelTo(now()->setDateTime(2026, 6, 1, 12, 0, 0));
});

it('uses the database store by default', function (): void {
    $user = $this->user();

    UserPresence::heartbeat($user);

    expect(UserPresence::store())->toBeInstanceOf(DatabasePresenceStore::class);
    $this->assertDatabaseHas('user_presences', [
        'presenceable_type' => $user->getMorphClass(),
        'presenceable_id' => $user->getKey(),
        'last_seen_at' => '2026-06-01 12:00:00',
    ]);
});

it('keeps heartbeats out of the database with the cache store', function (): void {
    config()->set('user-presence.presence.store', 'cache');
    $user = $this->user();

    UserPresence::heartbeat($user);

    expect(UserPresence::store())->toBeInstanceOf(CachePresenceStore::class)
        ->and(UserPresence::isOnline($user))->toBeTrue()
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');

    $this->assertDatabaseCount('user_presences', 0);

    $this->travel(4)->minutes();
    expect(UserPresence::isOnline($user))->toBeFalse();
});

it('keeps the previous activity in the cache store for passive heartbeats', function (): void {
    config()->set('user-presence.presence.store', 'cache');
    $user = $this->user();
    UserPresence::heartbeat($user);

    $this->travel(2)->minutes();
    UserPresence::heartbeat($user, interactive: false);

    expect(UserPresence::lastActivityAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:02:00');
});

it('loses cached presence when the cache is flushed', function (): void {
    config()->set('user-presence.presence.store', 'cache');
    $user = $this->user();
    UserPresence::heartbeat($user);

    Cache::flush();

    expect(UserPresence::lastSeenAt($user))->toBeNull();
});

it('honours logout with the cache store', function (): void {
    config()->set('user-presence.presence.store', 'cache');
    $user = $this->user();
    UserPresence::recordLogin($user);

    UserPresence::recordLogout($user);

    expect(UserPresence::isOnline($user))->toBeFalse();
});

it('accepts custom presence stores', function (): void {
    $store = new class implements PresenceStore
    {
        /** @var array<string, PresenceTimestamps> */
        public array $data = [];

        public function get(Model $user): PresenceTimestamps
        {
            return $this->data[(string) $user->getKey()] ?? PresenceTimestamps::empty();
        }

        public function touch(Model $user, CarbonImmutable $seenAt, ?CarbonImmutable $activityAt = null): void
        {
            $this->data[(string) $user->getKey()] = new PresenceTimestamps($seenAt, $activityAt ?? $this->get($user)->lastActivityAt);
        }

        public function forget(Model $user): void
        {
            unset($this->data[(string) $user->getKey()]);
        }
    };

    UserPresence::extendStore('memory', fn (Container $app): PresenceStore => $store);
    config()->set('user-presence.presence.store', 'memory');
    $user = $this->user();

    UserPresence::heartbeat($user);

    expect(UserPresence::isOnline($user))->toBeTrue()
        ->and($store->data)->toHaveKey((string) $user->getKey());
    $this->assertDatabaseCount('user_presences', 0);
});

it('can replace a built-in store', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    UserPresence::extendStore('database', fn (Container $app): PresenceStore => new CachePresenceStore(Cache::store('array')));

    expect(UserPresence::store())->toBeInstanceOf(CachePresenceStore::class)
        ->and(UserPresence::lastSeenAt($user))->toBeNull();
});

it('rejects stores that do not implement the contract', function (): void {
    UserPresence::extendStore('broken', fn (): stdClass => new stdClass);
    config()->set('user-presence.presence.store', 'broken');

    UserPresence::isOnline($this->user());
})->throws(UnsupportedPresenceStore::class);

it('queries online users with the database store', function (): void {
    $online = $this->user('Online');
    $expired = $this->user('Expired');
    $loggedOut = $this->user('LoggedOut');
    $this->user('Never');

    UserPresence::heartbeat($expired);
    $this->travel(5)->minutes();
    UserPresence::heartbeat($online);
    UserPresence::recordLogin($loggedOut);
    UserPresence::recordLogout($loggedOut);

    expect(User::query()->online()->pluck('name')->all())->toBe(['Online']);
});

it('refuses to query online users with the cache store', function (): void {
    config()->set('user-presence.presence.store', 'cache');

    User::query()->online()->get();
})->throws(UnsupportedPresenceStore::class);

it('reads eager loaded presence records without extra queries', function (): void {
    foreach (['A', 'B', 'C'] as $name) {
        UserPresence::heartbeat($this->user($name));
    }

    $users = User::query()->with('presenceRecord')->get();

    DB::enableQueryLog();
    $users->each(fn (User $user): Syriable\UserPresence\Data\Presence => $user->presence());

    expect(DB::getQueryLog())->toBeEmpty();
});

it('loads the full snapshot with a single query', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    DB::enableQueryLog();
    $presence = $user->presence();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($presence->isOnline())->toBeTrue()
        ->and($presence->toArray())->toHaveKeys(['status', 'last_seen_at', 'locale', 'timezone', 'local_time', 'location']);
});

it('does not overwrite other columns when writing concurrently', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'Europe/Berlin');

    UserPresence::heartbeat($user);

    expect(UserPresence::timezone($user))->toBe('Europe/Berlin');
    $this->assertDatabaseCount('user_presences', 1);
});
