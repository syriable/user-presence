<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Syriable\UserPresence\Enums\PresenceStatus;
use Syriable\UserPresence\Events\UserCameOnline;
use Syriable\UserPresence\Facades\UserPresence;

beforeEach(function (): void {
    $this->travelTo(now()->setDateTime(2026, 6, 1, 12, 0, 0));
});

it('treats a user without any heartbeat as offline', function (): void {
    $user = $this->user();

    expect(UserPresence::isOnline($user))->toBeFalse()
        ->and(UserPresence::status($user))->toBe(PresenceStatus::Offline)
        ->and(UserPresence::lastSeenAt($user))->toBeNull()
        ->and(UserPresence::lastActivityAt($user))->toBeNull();
});

it('marks a user online after a heartbeat', function (): void {
    $user = $this->user();

    expect(UserPresence::heartbeat($user))->toBeTrue()
        ->and(UserPresence::isOnline($user))->toBeTrue()
        ->and($user->isOnline())->toBeTrue()
        ->and(UserPresence::status($user))->toBe(PresenceStatus::Online)
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(UserPresence::lastActivityAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});

it('marks a user offline once the heartbeat expires', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    $this->travel(180)->seconds();
    expect(UserPresence::isOnline($user))->toBeTrue();

    $this->travel(1)->seconds();
    expect(UserPresence::isOnline($user))->toBeFalse()
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});

it('uses the configured expiration', function (): void {
    config()->set('user-presence.presence.expiration', 30);
    $user = $this->user();
    UserPresence::heartbeat($user);

    $this->travel(31)->seconds();

    expect(UserPresence::isOnline($user))->toBeFalse();
});

it('throttles repeated heartbeats within the interval', function (): void {
    $user = $this->user();

    expect(UserPresence::heartbeat($user))->toBeTrue();

    $this->travel(30)->seconds();
    expect(UserPresence::heartbeat($user))->toBeFalse()
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');

    $this->travel(31)->seconds();
    expect(UserPresence::heartbeat($user))->toBeTrue()
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:01:01');
});

it('records every heartbeat when the interval is zero', function (): void {
    config()->set('user-presence.presence.heartbeat.interval', 0);
    $user = $this->user();

    expect(UserPresence::heartbeat($user))->toBeTrue()
        ->and(UserPresence::heartbeat($user))->toBeTrue();
});

it('keeps last activity unchanged for passive heartbeats', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    $this->travel(2)->minutes();
    UserPresence::heartbeat($user, interactive: false);

    expect(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:02:00')
        ->and(UserPresence::lastActivityAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(UserPresence::isOnline($user))->toBeTrue();
});

it('tracks multiple users independently', function (): void {
    $jane = $this->user('Jane');
    $john = $this->user('John');

    UserPresence::heartbeat($jane);

    expect(UserPresence::isOnline($jane))->toBeTrue()
        ->and(UserPresence::isOnline($john))->toBeFalse();

    UserPresence::heartbeat($john);

    expect(UserPresence::isOnline($john))->toBeTrue();
});

it('does nothing while presence tracking is disabled', function (): void {
    config()->set('user-presence.presence.enabled', false);
    $user = $this->user();

    expect(UserPresence::heartbeat($user))->toBeFalse()
        ->and(UserPresence::isOnline($user))->toBeFalse()
        ->and(UserPresence::lastSeenAt($user))->toBeNull();

    $this->assertDatabaseCount('user_presences', 0);
});

it('does nothing while heartbeats are disabled', function (): void {
    config()->set('user-presence.presence.heartbeat.enabled', false);

    expect(UserPresence::heartbeat($this->user()))->toBeFalse();
});

it('lets the application filter heartbeats', function (): void {
    $jane = $this->user('Jane');
    $john = $this->user('John');

    UserPresence::filterHeartbeatsUsing(fn (Model $user): bool => $user->getAttribute('name') !== 'John');

    expect(UserPresence::heartbeat($jane))->toBeTrue()
        ->and(UserPresence::heartbeat($john))->toBeFalse();

    UserPresence::filterHeartbeatsUsing(null);

    expect(UserPresence::heartbeat($john))->toBeTrue();
});

it('dispatches an event when an offline user comes online', function (): void {
    Event::fake([UserCameOnline::class]);
    $user = $this->user();

    UserPresence::heartbeat($user);
    $this->travel(61)->seconds();
    UserPresence::heartbeat($user);

    Event::assertDispatchedTimes(UserCameOnline::class, 1);

    $this->travel(10)->minutes();
    UserPresence::heartbeat($user);

    Event::assertDispatchedTimes(UserCameOnline::class, 2);
});

it('stores timestamps in UTC regardless of the application timezone', function (): void {
    config()->set('app.timezone', 'Asia/Tokyo');
    date_default_timezone_set('Asia/Tokyo');

    try {
        $user = $this->user();
        UserPresence::heartbeat($user);

        $this->assertDatabaseHas('user_presences', ['last_seen_at' => '2026-06-01 12:00:00']);

        expect(UserPresence::lastSeenAt($user)?->getTimezone()->getName())->toBe('UTC')
            ->and(UserPresence::isOnline($user))->toBeTrue();
    } finally {
        date_default_timezone_set('UTC');
    }
});
