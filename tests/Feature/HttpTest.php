<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Facades\UserPresence;

beforeEach(function (): void {
    $this->travelTo(now()->setDateTime(2026, 6, 1, 12, 0, 0));
    $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    Route::middleware('web')->get('/page', fn (): string => app()->getLocale());
    Route::middleware(['web', 'auth', 'user-presence.locale'])->get('/localized', fn (): string => app()->getLocale());
});

it('rejects heartbeats from guests', function (): void {
    $this->postJson(route('user-presence.heartbeat'))->assertUnauthorized();

    $this->assertDatabaseCount('user_presences', 0);
});

it('records a heartbeat for the authenticated user only', function (): void {
    $user = $this->user('Jane');
    $other = $this->user('John');

    $this->actingAs($user)
        ->postJson(route('user-presence.heartbeat'), ['user_id' => $other->getKey()])
        ->assertOk()
        ->assertExactJson(['status' => 'online', 'interval' => 60]);

    expect(UserPresence::isOnline($user))->toBeTrue()
        ->and(UserPresence::isOnline($other))->toBeFalse();
});

it('records passive heartbeats without updating last activity', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    $this->travel(2)->minutes();
    $this->actingAs($user)->postJson(route('user-presence.heartbeat'), ['interactive' => false])->assertOk();

    expect(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:02:00')
        ->and(UserPresence::lastActivityAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});

it('accepts a browser timezone hint', function (): void {
    $user = $this->user();

    $this->actingAs($user)->postJson(route('user-presence.heartbeat'), ['timezone' => 'Europe/Berlin'])->assertOk();

    expect(UserPresence::timezone($user))->toBe('Europe/Berlin')
        ->and(UserPresence::timezoneSource($user))->toBe(ContextSource::Browser);
});

it('ignores an invalid browser timezone hint but still records the heartbeat', function (): void {
    $user = $this->user();

    $this->actingAs($user)->postJson(route('user-presence.heartbeat'), ['timezone' => '+02:00'])->assertOk();

    expect(UserPresence::timezone($user))->toBe('UTC')
        ->and(UserPresence::isOnline($user))->toBeTrue();
});

it('validates heartbeat payload types', function (): void {
    $this->actingAs($this->user())
        ->postJson(route('user-presence.heartbeat'), ['timezone' => str_repeat('a', 100), 'interactive' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone', 'interactive']);
});

it('accepts a browser locale hint when detection is enabled', function (): void {
    config()->set('user-presence.locale.detect', true);
    config()->set('user-presence.locale.supported', ['en', 'ar']);
    $user = $this->user();

    $this->actingAs($user)->postJson(route('user-presence.heartbeat'), ['locale' => 'ar-SY'])->assertOk();
    expect(UserPresence::locale($user))->toBe('en');

    $this->actingAs($user)->postJson(route('user-presence.heartbeat'), ['locale' => 'ar'])->assertOk();
    expect(UserPresence::locale($user))->toBe('ar');
});

it('records heartbeats automatically for web requests', function (): void {
    $user = $this->user();

    $this->actingAs($user)->get('/page')->assertOk();

    expect(UserPresence::isOnline($user))->toBeTrue();
});

it('does not record anything for guests', function (): void {
    $this->get('/page')->assertOk();

    $this->assertDatabaseCount('user_presences', 0);
});

it('writes at most once per interval no matter how many requests are made', function (): void {
    $user = $this->user();
    $this->actingAs($user);

    $this->get('/page');
    $this->travel(10)->seconds();
    $this->get('/page');
    $this->get('/page');

    expect(UserPresence::lastSeenAt($user)?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});

it('can disable automatic heartbeat collection', function (): void {
    config()->set('user-presence.presence.heartbeat.enabled', false);
    $user = $this->user();

    $this->actingAs($user)->get('/page')->assertOk();

    expect(UserPresence::isOnline($user))->toBeFalse();
});

it('applies the user locale with the locale middleware', function (): void {
    config()->set('user-presence.locale.supported', ['en', 'ar']);
    $user = $this->user();
    UserPresence::setLocale($user, 'ar');

    $this->actingAs($user)->get('/localized')->assertOk()->assertSeeText('ar');
});

it('detects the locale once with the locale middleware', function (): void {
    config()->set('user-presence.locale.detect', true);
    config()->set('user-presence.locale.supported', ['en', 'de']);
    $user = $this->user();

    $this->actingAs($user)->get('/localized', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertSeeText('de');
    $this->actingAs($user)->get('/localized', ['Accept-Language' => 'en'])->assertSeeText('de');
});
