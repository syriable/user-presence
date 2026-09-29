<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\LocaleChanged;
use Syriable\UserPresence\Exceptions\InvalidLocale;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Tests\Fixtures\StaticLocaleResolver;

beforeEach(function (): void {
    config()->set('app.locale', 'en');
    config()->set('user-presence.locale.supported', ['en', 'ar', 'fr', 'de', 'zh-CN']);
});

it('falls back to the application locale when nothing is stored', function (): void {
    expect(UserPresence::locale($this->user()))->toBe('en');
});

it('falls back to the configured default locale', function (): void {
    config()->set('user-presence.locale.default', 'de');

    expect(UserPresence::locale($this->user()))->toBe('de');
});

it('stores a supported locale', function (): void {
    Event::fake([LocaleChanged::class]);
    $user = $this->user();

    UserPresence::setLocale($user, 'ar');

    expect(UserPresence::locale($user))->toBe('ar')
        ->and(UserPresence::localeSource($user))->toBe(ContextSource::User);

    Event::assertDispatched(LocaleChanged::class, fn (LocaleChanged $event): bool => $event->locale === 'ar' && $event->previousLocale === null);
});

it('normalizes locales to their configured spelling', function (string $input): void {
    $user = $this->user();

    UserPresence::setLocale($user, $input);

    expect(UserPresence::locale($user))->toBe('zh-CN');
})->with(['zh-CN', 'zh_CN', 'zh-cn', ' zh_cn ']);

it('rejects unsupported locales', function (): void {
    UserPresence::setLocale($this->user(), 'sv');
})->throws(InvalidLocale::class, 'not a supported interface locale');

it('rejects malformed locales', function (string $locale): void {
    UserPresence::setLocale($this->user(), $locale);
})->throws(InvalidLocale::class)->with(['', 'english', '12', 'en--US', '<script>']);

it('accepts any well-formed tag when no supported list is configured', function (): void {
    config()->set('user-presence.locale.supported', []);
    $user = $this->user();

    UserPresence::setLocale($user, 'sv_SE');

    expect(UserPresence::locale($user))->toBe('sv-SE');
});

it('clears a stored locale', function (): void {
    $user = $this->user();
    UserPresence::setLocale($user, 'fr');

    UserPresence::setLocale($user, null);

    expect(UserPresence::locale($user))->toBe('en')
        ->and(UserPresence::localeSource($user))->toBeNull();
});

it('falls back when a stored locale is no longer supported', function (): void {
    $user = $this->user();
    UserPresence::setLocale($user, 'fr');

    config()->set('user-presence.locale.supported', ['en']);

    expect(UserPresence::locale($user))->toBe('en');
});

it('detects the browser locale from Accept-Language', function (): void {
    config()->set('user-presence.locale.detect', true);
    $user = $this->user();
    $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'sv-SE,de-AT;q=0.8,en;q=0.5']);

    expect(UserPresence::detectLocale($user, $request))->toBeTrue()
        ->and(UserPresence::locale($user))->toBe('de')
        ->and(UserPresence::localeSource($user))->toBe(ContextSource::Browser);
});

it('does not detect locales unless enabled', function (): void {
    $user = $this->user();
    $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar']);

    expect(UserPresence::detectLocale($user, $request))->toBeFalse()
        ->and(UserPresence::recordDetectedLocale($user, 'ar'))->toBeFalse()
        ->and(UserPresence::locale($user))->toBe('en');
});

it('never overrides an explicit preference with a detected locale', function (): void {
    config()->set('user-presence.locale.detect', true);
    $user = $this->user();
    UserPresence::setLocale($user, 'fr');

    expect(UserPresence::recordDetectedLocale($user, 'ar'))->toBeFalse()
        ->and(UserPresence::detectLocale($user, Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar'])))->toBeFalse()
        ->and(UserPresence::locale($user))->toBe('fr');
});

it('lets an explicit preference override a detected locale', function (): void {
    config()->set('user-presence.locale.detect', true);
    $user = $this->user();
    UserPresence::recordDetectedLocale($user, 'ar');

    UserPresence::setLocale($user, 'de');

    expect(UserPresence::locale($user))->toBe('de')
        ->and(UserPresence::localeSource($user))->toBe(ContextSource::User);
});

it('ignores unsupported detected locales', function (): void {
    config()->set('user-presence.locale.detect', true);
    $user = $this->user();

    expect(UserPresence::recordDetectedLocale($user, 'ja'))->toBeFalse()
        ->and(UserPresence::detectLocale($user, Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja,ko'])))->toBeFalse()
        ->and(UserPresence::locale($user))->toBe('en');
});

it('consults custom locale resolvers', function (): void {
    $user = $this->user();

    UserPresence::registerLocaleResolver(new StaticLocaleResolver(null));
    UserPresence::registerLocaleResolver(new StaticLocaleResolver('xx'));
    UserPresence::registerLocaleResolver(new StaticLocaleResolver('fr'));

    expect(UserPresence::locale($user))->toBe('fr');

    UserPresence::setLocale($user, 'ar');

    expect(UserPresence::locale($user))->toBe('ar');
});

it('reads locale resolvers from the configuration', function (): void {
    config()->set('user-presence.locale.resolvers', [StaticLocaleResolver::class]);

    expect(UserPresence::locale($this->user()))->toBe('fr');
});

it('keeps spoken languages separate from the interface locale', function (): void {
    $user = $this->user();

    UserPresence::setSpokenLanguages($user, ['ar', 'sv', 'en_GB', 'AR']);

    expect(UserPresence::spokenLanguages($user))->toBe(['ar', 'sv', 'en-GB'])
        ->and(UserPresence::locale($user))->toBe('en');
});

it('rejects malformed spoken languages', function (): void {
    UserPresence::setSpokenLanguages($this->user(), ['arabic']);
})->throws(InvalidLocale::class);

it('returns defaults while locale management is disabled', function (): void {
    config()->set('user-presence.locale.enabled', false);
    $user = $this->user();

    UserPresence::setLocale($user, 'ar');
    UserPresence::setSpokenLanguages($user, ['ar']);

    expect(UserPresence::locale($user))->toBe('en')
        ->and(UserPresence::spokenLanguages($user))->toBe([]);
});
