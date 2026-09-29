<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Syriable\UserPresence\Http\Controllers\HeartbeatController;
use Syriable\UserPresence\Support\PresenceConfig;

$config = app(PresenceConfig::class);

if (! $config->routesEnabled()) {
    return;
}

Route::middleware($config->routeMiddleware())
    ->prefix($config->routePrefix())
    ->group(static function (): void {
        Route::post('heartbeat', HeartbeatController::class)->name('user-presence.heartbeat');
    });
