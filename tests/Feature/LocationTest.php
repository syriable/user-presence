<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Syriable\UserPresence\Data\Location;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\LocationChanged;
use Syriable\UserPresence\Exceptions\InvalidLocation;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Location\HeaderLocationProvider;
use Syriable\UserPresence\Tests\Fixtures\StaticLocationProvider;

beforeEach(function (): void {
    config()->set('user-presence.location.enabled', true);
});

it('is disabled by default and collects nothing', function (): void {
    config()->set('user-presence.location.enabled', false);
    $user = $this->user();
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(city: 'Berlin', countryCode: 'DE')));

    UserPresence::setLocation($user, new Location(city: 'Stockholm', countryCode: 'SE'));
    $detected = UserPresence::detectLocation($user, Request::create('/'));

    expect($detected)->toBeNull()
        ->and(UserPresence::location($user))->toBeNull()
        ->and(UserPresence::city($user))->toBeNull();

    $this->assertDatabaseCount('user_presences', 0);
});

it('returns null when no location is known', function (): void {
    $user = $this->user();

    expect(UserPresence::location($user))->toBeNull()
        ->and(UserPresence::countryCode($user))->toBeNull();
});

it('stores a user provided location', function (): void {
    Event::fake([LocationChanged::class]);
    $user = $this->user();

    UserPresence::setLocation($user, new Location(city: ' Stockholm ', countryCode: 'se'));

    $location = UserPresence::location($user);

    expect($location?->city)->toBe('Stockholm')
        ->and($location?->countryCode)->toBe('SE')
        ->and($location?->source)->toBe(ContextSource::User)
        ->and($location?->isInferred())->toBeFalse()
        ->and(UserPresence::city($user))->toBe('Stockholm');

    Event::assertDispatched(LocationChanged::class);
});

it('derives the country name from the country code', function (): void {
    $user = $this->user();

    UserPresence::setLocation($user, new Location(countryCode: 'DE'));

    expect(UserPresence::countryName($user))->toBe('Germany');
})->skip(! class_exists(Locale::class), 'Requires ext-intl.');

it('rejects invalid country codes provided by the application', function (): void {
    UserPresence::setLocation($this->user(), new Location(countryCode: 'Germany'));
})->throws(InvalidLocation::class);

it('clears the location', function (): void {
    $user = $this->user();
    UserPresence::setLocation($user, new Location(city: 'Berlin'));

    UserPresence::setLocation($user, null);

    expect(UserPresence::location($user))->toBeNull();
});

it('detects a location with a provider and marks it as inferred', function (): void {
    $user = $this->user();
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(city: 'Berlin', countryCode: 'DE')));

    $location = UserPresence::detectLocation($user, Request::create('/'));

    expect($location?->city)->toBe('Berlin')
        ->and(UserPresence::location($user)?->source)->toBe(ContextSource::Provider)
        ->and(UserPresence::location($user)?->isInferred())->toBeTrue();
});

it('tries providers in order and skips failing or empty ones', function (): void {
    $user = $this->user();

    UserPresence::registerLocationProvider(new StaticLocationProvider(fails: true));
    UserPresence::registerLocationProvider(new StaticLocationProvider);
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(countryCode: 'XX')));
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(city: 'Paris', countryCode: 'FR')));

    expect(UserPresence::detectLocation($user, Request::create('/'))?->city)->toBe('Paris');
});

it('discards malformed fields from provider responses', function (): void {
    $user = $this->user();
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(
        city: '<b>'.str_repeat('a', 300).'</b>',
        countryCode: 'not-a-code',
    )));

    $location = UserPresence::detectLocation($user, Request::create('/'));

    expect($location?->countryCode)->toBeNull()
        ->and(mb_strlen((string) $location?->city))->toBe(120)
        ->and($location?->city)->not->toContain('<b>');
});

it('never replaces a user provided location with a detected one', function (): void {
    $user = $this->user();
    UserPresence::setLocation($user, new Location(city: 'Stockholm', countryCode: 'SE'));
    UserPresence::registerLocationProvider(new StaticLocationProvider(new Location(city: 'Berlin', countryCode: 'DE')));

    expect(UserPresence::detectLocation($user, Request::create('/')))->toBeNull()
        ->and(UserPresence::city($user))->toBe('Stockholm');
});

it('reads locations from trusted CDN headers', function (): void {
    config()->set('user-presence.location.providers', [HeaderLocationProvider::class]);
    $user = $this->user();
    $request = Request::create('/', server: ['HTTP_CF_IPCOUNTRY' => 'se', 'HTTP_CF_IPCITY' => 'Malmö']);

    $location = UserPresence::detectLocation($user, $request);

    expect($location?->countryCode)->toBe('SE')
        ->and($location?->city)->toBe('Malmö')
        ->and($location?->source)->toBe(ContextSource::Provider);
});

it('detects the location on login when configured', function (): void {
    config()->set('user-presence.location.providers', [HeaderLocationProvider::class]);
    $user = $this->user();
    $this->app->instance('request', Request::create('/', server: ['HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'DE']));

    Auth::guard('web')->login($user);

    expect(UserPresence::countryCode($user))->toBe('DE');

    config()->set('user-presence.location.detect_on_login', false);
    $other = $this->user('Other');
    Auth::guard('web')->login($other);

    expect(UserPresence::countryCode($other))->toBeNull();
});

it('replaces providers through the configuration', function (): void {
    config()->set('user-presence.location.providers', []);
    $user = $this->user();
    $request = Request::create('/', server: ['HTTP_CF_IPCOUNTRY' => 'SE']);

    expect(UserPresence::detectLocation($user, $request))->toBeNull();
});
