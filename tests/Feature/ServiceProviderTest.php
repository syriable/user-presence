<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Http\Middleware\ApplyUserLocale;
use Syriable\UserPresence\Http\Middleware\RecordHeartbeat;
use Syriable\UserPresence\Models\PresenceRecord;
use Syriable\UserPresence\Tests\Fixtures\PlainUser;
use Syriable\UserPresence\UserPresenceManager;

it('registers the manager as a singleton behind the facade', function (): void {
    expect(app(UserPresenceManager::class))->toBe(app(UserPresenceManager::class))
        ->and(UserPresence::getFacadeRoot())->toBeInstanceOf(UserPresenceManager::class);
});

it('merges the default configuration', function (): void {
    expect(config('user-presence.presence.expiration'))->toBe(180)
        ->and(config('user-presence.location.enabled'))->toBeFalse()
        ->and(config('user-presence.privacy.store_ip_address'))->toBeFalse();
});

it('publishes the configuration and migration', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'user-presence-config', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'user-presence-migrations', '--force' => true])->assertSuccessful();

    expect(config_path('user-presence.php'))->toBeFile()
        ->and(glob(database_path('migrations/*_create_user_presences_table.php')))->not->toBeEmpty();

    @unlink(config_path('user-presence.php'));
    array_map(unlink(...), glob(database_path('migrations/*_create_user_presences_table.php')) ?: []);
});

it('registers the heartbeat route and middleware', function (): void {
    $router = app(Router::class);

    expect(Route::has('user-presence.heartbeat'))->toBeTrue()
        ->and($router->getMiddleware())->toMatchArray([
            'user-presence.heartbeat' => RecordHeartbeat::class,
            'user-presence.locale' => ApplyUserLocale::class,
        ])
        ->and(app(Kernel::class)->getMiddlewareGroups()['web'])->toContain(RecordHeartbeat::class);
});

it('works with models that do not use the trait', function (): void {
    $user = PlainUser::query()->create(['name' => 'Plain', 'email' => 'plain@example.com']);

    UserPresence::heartbeat($user);
    UserPresence::setTimezone($user, 'Europe/Berlin');

    expect(UserPresence::isOnline($user))->toBeTrue()
        ->and(UserPresence::timezone($user))->toBe('Europe/Berlin')
        ->and(UserPresence::get($user)->timezone)->toBe('Europe/Berlin');
});

it('supports a custom model and table name', function (): void {
    Illuminate\Support\Facades\Schema::rename('user_presences', 'member_contexts');
    config()->set('user-presence.table_name', 'member_contexts');
    config()->set('user-presence.model', CustomPresenceRecord::class);

    $user = $this->user();
    UserPresence::setTimezone($user, 'Asia/Shanghai');

    expect($user->presenceRecord()->first())->toBeInstanceOf(CustomPresenceRecord::class)
        ->and(UserPresence::timezone($user))->toBe('Asia/Shanghai');
    $this->assertDatabaseHas('member_contexts', ['timezone' => 'Asia/Shanghai']);
});

it('rejects models that do not extend the package model', function (): void {
    config()->set('user-presence.model', PlainUser::class);

    UserPresence::timezone($this->user());
})->throws(InvalidArgumentException::class, 'must extend');

it('deletes presence data together with the user', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);

    $user->delete();
    $this->assertDatabaseCount('user_presences', 1);

    $user->forceDelete();
    $this->assertDatabaseCount('user_presences', 0);
});

it('keeps presence data on deletion when configured', function (): void {
    config()->set('user-presence.privacy.delete_with_user', false);
    $user = $this->user();
    UserPresence::heartbeat($user);

    $user->forceDelete();

    $this->assertDatabaseCount('user_presences', 1);
});

it('forgets all data for a user', function (): void {
    $user = $this->user();
    UserPresence::heartbeat($user);
    UserPresence::setPreference($user, 'show_online_status', false);

    UserPresence::forget($user);

    expect(UserPresence::lastSeenAt($user))->toBeNull()
        ->and(UserPresence::preferences($user))->toBe([]);
    $this->assertDatabaseCount('user_presences', 0);
});

it('stores application-defined preferences', function (): void {
    $user = $this->user();

    UserPresence::setPreference($user, 'show_online_status', false);
    UserPresence::setPreference($user, 'working_hours', ['start' => '09:00', 'end' => '17:00']);

    expect(UserPresence::preference($user, 'show_online_status'))->toBeFalse()
        ->and(UserPresence::preference($user, 'missing', 'fallback'))->toBe('fallback')
        ->and(UserPresence::preferences($user))->toHaveKeys(['show_online_status', 'working_hours']);

    UserPresence::setPreference($user, 'working_hours', null);

    expect(UserPresence::preferences($user))->toBe(['show_online_status' => false]);
});

it('rejects invalid preference keys and values', function (string $key, mixed $value): void {
    UserPresence::setPreference($this->user(), $key, $value);
})->throws(InvalidArgumentException::class)->with([
    'empty key' => ['', true],
    'spaces' => ['show online', true],
    'object' => ['key', new stdClass],
]);

it('hides the login ip address when serializing the record', function (): void {
    config()->set('user-presence.privacy.store_ip_address', true);
    $user = $this->user();
    UserPresence::recordLogin($user, '203.0.113.7');

    expect($user->presenceRecord()->firstOrFail()->toArray())->not->toHaveKey('last_login_ip');
});

final class CustomPresenceRecord extends PresenceRecord {}
