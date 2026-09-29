<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Syriable\UserPresence\Facades\UserPresence;
use Syriable\UserPresence\Http\Middleware\RecordHeartbeat;

it('does not register the heartbeat endpoint when routes are disabled', function (): void {
    expect(Route::has('user-presence.heartbeat'))->toBeFalse();
});

it('does not push the heartbeat middleware when no groups are configured', function (): void {
    expect(app(Kernel::class)->getMiddlewareGroups()['web'])->not->toContain(RecordHeartbeat::class);
});

it('still records heartbeats from application code', function (): void {
    $user = $this->user();

    UserPresence::heartbeat($user);

    expect(UserPresence::isOnline($user))->toBeTrue();
});
