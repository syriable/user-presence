<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Syriable\UserPresence\Data\Location;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\TimezoneChanged;
use Syriable\UserPresence\Exceptions\InvalidTimezone;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Tests\Fixtures\StaticTimezoneResolver;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));
});

it('falls back to UTC, not the server timezone', function (): void {
    config()->set('app.timezone', 'Europe/Berlin');

    expect(UserPresence::timezone($this->user()))->toBe('UTC');
});

it('falls back to the configured default timezone', function (): void {
    config()->set('user-presence.timezone.default', 'Europe/Stockholm');

    expect(UserPresence::timezone($this->user()))->toBe('Europe/Stockholm');
});

it('falls back to UTC when the configured default is invalid', function (): void {
    config()->set('user-presence.timezone.default', 'Mars/Olympus');

    expect(UserPresence::timezone($this->user()))->toBe('UTC');
});

it('stores a valid IANA timezone', function (string $timezone): void {
    $user = $this->user();

    UserPresence::setTimezone($user, $timezone);

    expect(UserPresence::timezone($user))->toBe($timezone)
        ->and(UserPresence::timezoneSource($user))->toBe(ContextSource::User);
})->with(['Europe/Berlin', 'Europe/Stockholm', 'Asia/Shanghai', 'America/New_York', 'UTC']);

it('rejects invalid timezones and fixed offsets', function (string $timezone): void {
    UserPresence::setTimezone($this->user(), $timezone);
})->throws(InvalidTimezone::class)->with(['+01:00', 'GMT+1', 'CET', 'Mars/Olympus', '', 'europe/berlin']);

it('updates and clears the timezone', function (): void {
    Event::fake([TimezoneChanged::class]);
    $user = $this->user();

    UserPresence::setTimezone($user, 'Europe/Berlin');
    UserPresence::setTimezone($user, 'Asia/Shanghai');

    expect(UserPresence::timezone($user))->toBe('Asia/Shanghai');

    UserPresence::setTimezone($user, null);

    expect(UserPresence::timezone($user))->toBe('UTC');

    Event::assertDispatchedTimes(TimezoneChanged::class, 3);
});

it('returns the current local time of a user', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'Asia/Shanghai');

    $localTime = UserPresence::localTime($user);

    expect($localTime->format('Y-m-d H:i T'))->toBe('2026-06-01 20:00 CST')
        ->and($localTime->getTimezone()->getName())->toBe('Asia/Shanghai');
});

it('converts a timestamp to the local time of a user', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'America/New_York');

    $local = UserPresence::localTime($user, CarbonImmutable::parse('2026-01-15 15:00:00', 'UTC'));

    expect($local->format('Y-m-d H:i'))->toBe('2026-01-15 10:00');
});

it('calculates the time difference between two users', function (): void {
    $berlin = $this->user('Berlin');
    $shanghai = $this->user('Shanghai');
    UserPresence::setTimezone($berlin, 'Europe/Berlin');
    UserPresence::setTimezone($shanghai, 'Asia/Shanghai');

    $difference = UserPresence::timeDifference($berlin, $shanghai);

    expect($difference->seconds)->toBe(6 * 3600)
        ->and($difference->format())->toBe('+06:00')
        ->and($difference->isAhead())->toBeTrue()
        ->and(UserPresence::timeDifference($shanghai, $berlin)->format())->toBe('-06:00');
});

it('reports no difference for users in the same timezone', function (): void {
    $a = $this->user('A');
    $b = $this->user('B');
    UserPresence::setTimezone($a, 'Europe/Stockholm');
    UserPresence::setTimezone($b, 'Europe/Stockholm');

    expect(UserPresence::timeDifference($a, $b)->isSame())->toBeTrue();
});

it('handles half-hour offsets', function (): void {
    $utc = $this->user('Utc');
    $india = $this->user('India');
    UserPresence::setTimezone($india, 'Asia/Kolkata');

    expect(UserPresence::timeDifference($utc, $india)->format())->toBe('+05:30')
        ->and(UserPresence::timeDifference($utc, $india)->inMinutes())->toBe(330);
});

it('follows daylight saving time transitions', function (): void {
    $berlin = $this->user('Berlin');
    $newYork = $this->user('NewYork');
    UserPresence::setTimezone($berlin, 'Europe/Berlin');
    UserPresence::setTimezone($newYork, 'America/New_York');

    // The US switches to summer time three weeks before Europe does.
    expect(UserPresence::timeDifference($berlin, $newYork, CarbonImmutable::parse('2026-03-01 12:00', 'UTC'))->format())->toBe('-06:00')
        ->and(UserPresence::timeDifference($berlin, $newYork, CarbonImmutable::parse('2026-03-15 12:00', 'UTC'))->format())->toBe('-05:00')
        ->and(UserPresence::timeDifference($berlin, $newYork, CarbonImmutable::parse('2026-04-15 12:00', 'UTC'))->format())->toBe('-06:00');

    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00', 'UTC'));

    expect(UserPresence::timeDifference($berlin, $newYork)->inHours())->toBe(-5.0);
});

it('converts local time correctly across a daylight saving change', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'Europe/Stockholm');

    expect(UserPresence::localTime($user, CarbonImmutable::parse('2026-03-29 00:59:59', 'UTC'))->format('H:i:s P'))->toBe('01:59:59 +01:00')
        ->and(UserPresence::localTime($user, CarbonImmutable::parse('2026-03-29 01:00:00', 'UTC'))->format('H:i:s P'))->toBe('03:00:00 +02:00');
});

it('uses a browser detected timezone when the user has not chosen one', function (): void {
    $user = $this->user();

    expect(UserPresence::recordDetectedTimezone($user, 'Europe/Berlin'))->toBeTrue()
        ->and(UserPresence::timezone($user))->toBe('Europe/Berlin')
        ->and(UserPresence::timezoneSource($user))->toBe(ContextSource::Browser);

    UserPresence::recordDetectedTimezone($user, 'Europe/Paris');

    expect(UserPresence::timezone($user))->toBe('Europe/Paris');
});

it('never overrides an explicit timezone with a detected one', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'Asia/Shanghai');

    expect(UserPresence::recordDetectedTimezone($user, 'Europe/Berlin'))->toBeFalse()
        ->and(UserPresence::timezone($user))->toBe('Asia/Shanghai');
});

it('ignores invalid detected timezones', function (): void {
    $user = $this->user();

    expect(UserPresence::recordDetectedTimezone($user, '+02:00'))->toBeFalse()
        ->and(UserPresence::timezone($user))->toBe('UTC');
});

it('ignores detected timezones when detection is disabled', function (): void {
    config()->set('user-presence.timezone.detect', false);

    expect(UserPresence::recordDetectedTimezone($this->user(), 'Europe/Berlin'))->toBeFalse();
});

it('consults custom timezone resolvers after stored values', function (): void {
    $user = $this->user();

    UserPresence::registerTimezoneResolver(new StaticTimezoneResolver('Not/AZone'));
    UserPresence::registerTimezoneResolver(StaticTimezoneResolver::class);

    expect(UserPresence::timezone($user))->toBe('America/New_York');

    UserPresence::recordDetectedTimezone($user, 'Europe/Berlin');

    expect(UserPresence::timezone($user))->toBe('Europe/Berlin');
});

it('derives the timezone from a single-timezone country', function (): void {
    config()->set('user-presence.location.enabled', true);
    $sweden = $this->user('Sweden');
    $unitedStates = $this->user('States');

    UserPresence::setLocation($sweden, new Location(countryCode: 'SE'));
    UserPresence::setLocation($unitedStates, new Location(countryCode: 'US'));

    expect(UserPresence::timezone($sweden))->toBe('Europe/Stockholm')
        ->and(UserPresence::timezone($unitedStates))->toBe('UTC');
});

it('returns the default while timezone management is disabled', function (): void {
    config()->set('user-presence.timezone.enabled', false);
    $user = $this->user();

    UserPresence::setTimezone($user, 'Asia/Shanghai');

    expect(UserPresence::timezone($user))->toBe('UTC');
});

it('falls back when a stored timezone becomes invalid', function (): void {
    $user = $this->user();
    UserPresence::setTimezone($user, 'Europe/Berlin');
    $user->presenceRecord()->update(['timezone' => 'Invalid/Zone']);

    expect(UserPresence::timezone($user))->toBe('UTC');
});
