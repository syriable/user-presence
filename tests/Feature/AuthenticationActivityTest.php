<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Syriable\UserPresence\Events\UserWentOffline;
use Syriable\UserPresence\Facades\UserPresence;

beforeEach(function (): void {
    $this->travelTo(now()->setDateTime(2026, 6, 1, 12, 0, 0));
});

it('returns null timestamps for users who never logged in', function (): void {
    $user = $this->user();

    expect(UserPresence::lastLoginAt($user))->toBeNull()
        ->and(UserPresence::lastLogoutAt($user))->toBeNull();
});

it('records the login time and marks the user online', function (): void {
    $user = $this->user();

    Auth::guard('web')->login($user);

    expect(UserPresence::lastLoginAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(UserPresence::isOnline($user))->toBeTrue();
});

it('does not record a new login for every authenticated request', function (): void {
    $user = $this->user();
    Auth::guard('web')->login($user);

    $this->travel(1)->hour();
    $this->actingAs($user)->get('/presence-test-page');

    expect(UserPresence::lastLoginAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});

it('records the logout time and marks the user offline', function (): void {
    Event::fake([UserWentOffline::class]);
    $user = $this->user();
    Auth::guard('web')->login($user);

    $this->travel(1)->minute();
    Auth::guard('web')->logout();

    expect(UserPresence::lastLogoutAt($user)?->toDateTimeString())->toBe('2026-06-01 12:01:00')
        ->and(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:01:00')
        ->and(UserPresence::isOnline($user))->toBeFalse();

    Event::assertDispatched(UserWentOffline::class);
});

it('brings the user back online when another device keeps sending heartbeats', function (): void {
    $user = $this->user();
    UserPresence::recordLogin($user);
    UserPresence::recordLogout($user);

    $this->travel(5)->seconds();

    expect(UserPresence::heartbeat($user))->toBeTrue()
        ->and(UserPresence::isOnline($user))->toBeTrue();
});

it('only tracks the configured guards', function (): void {
    $admin = $this->admin();

    Auth::guard('admin')->login($admin);
    expect(UserPresence::lastLoginAt($admin))->toBeNull();

    config()->set('user-presence.guards', ['web', 'admin']);
    Auth::guard('admin')->login($admin);
    expect(UserPresence::lastLoginAt($admin))->not->toBeNull();
});

it('keeps users of different models apart', function (): void {
    config()->set('user-presence.guards', ['web', 'admin']);
    $user = $this->user();
    $admin = $this->admin();

    expect($user->getKey())->toBe($admin->getKey());

    Auth::guard('admin')->login($admin);

    expect(UserPresence::isOnline($admin))->toBeTrue()
        ->and(UserPresence::isOnline($user))->toBeFalse();
});

it('does not store the ip address unless configured', function (): void {
    $user = $this->user();

    UserPresence::recordLogin($user, '203.0.113.7');
    $this->assertDatabaseHas('user_presences', ['last_login_ip' => null]);

    config()->set('user-presence.privacy.store_ip_address', true);
    UserPresence::recordLogin($user, '203.0.113.7');
    $this->assertDatabaseHas('user_presences', ['last_login_ip' => '203.0.113.7']);

    config()->set('user-presence.privacy.store_ip_address', false);
    UserPresence::recordLogin($user, '203.0.113.8');
    $this->assertDatabaseHas('user_presences', ['last_login_ip' => null]);
});

it('ignores authentication events when authentication tracking is disabled', function (): void {
    config()->set('user-presence.authentication.enabled', false);
    $user = $this->user();

    Auth::guard('web')->login($user);
    Auth::guard('web')->logout();

    expect(UserPresence::lastLoginAt($user))->toBeNull()
        ->and(UserPresence::lastLogoutAt($user))->toBeNull();
});
