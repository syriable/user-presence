<?php

declare(strict_types=1);

namespace Syriable\UserPresence;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\UserPresence\Http\Middleware\ApplyUserLocale;
use Syriable\UserPresence\Http\Middleware\RecordHeartbeat;
use Syriable\UserPresence\Listeners\RecordAuthenticationActivity;
use Syriable\UserPresence\Services\LocaleService;
use Syriable\UserPresence\Services\LocationService;
use Syriable\UserPresence\Services\PreferenceService;
use Syriable\UserPresence\Services\PresenceService;
use Syriable\UserPresence\Services\TimezoneService;
use Syriable\UserPresence\Storage\PresenceStoreManager;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;

final class UserPresenceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('user-presence')
            ->hasConfigFile()
            ->hasMigration('create_user_presences_table')
            ->hasRoute('web')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PresenceConfig::class);
        $this->app->singleton(PresenceRepository::class);
        $this->app->singleton(PresenceStoreManager::class);
        $this->app->singleton(PresenceService::class);
        $this->app->singleton(LocaleService::class);
        $this->app->singleton(TimezoneService::class);
        $this->app->singleton(LocationService::class);
        $this->app->singleton(PreferenceService::class);
        $this->app->singleton(UserPresenceManager::class);
    }

    public function packageBooted(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(Login::class, [RecordAuthenticationActivity::class, 'handleLogin']);
        $events->listen(Logout::class, [RecordAuthenticationActivity::class, 'handleLogout']);
        $events->listen(CurrentDeviceLogout::class, [RecordAuthenticationActivity::class, 'handleLogout']);

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('user-presence.heartbeat', RecordHeartbeat::class);
        $router->aliasMiddleware('user-presence.locale', ApplyUserLocale::class);

        // Groups are appended through the HTTP kernel, which owns the group
        // definitions and syncs them to the router.
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            $config = $this->app->make(PresenceConfig::class);

            if (! $kernel instanceof HttpKernel || ! $config->heartbeatEnabled()) {
                return;
            }

            foreach ($config->heartbeatMiddlewareGroups() as $group) {
                if (array_key_exists($group, $kernel->getMiddlewareGroups())) {
                    $kernel->appendMiddlewareToGroup($group, RecordHeartbeat::class);
                }
            }
        });
    }
}
