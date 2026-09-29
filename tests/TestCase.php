<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Syriable\UserPresence\Tests\Fixtures\Admin;
use Syriable\UserPresence\Tests\Fixtures\User;
use Syriable\UserPresence\UserPresenceServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [UserPresenceServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
        $app['config']->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => Admin::class]);
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['users', 'admins'] as $table) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->default('secret');
                $table->rememberToken();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        (require __DIR__.'/../database/migrations/create_user_presences_table.php.stub')->up();
    }

    protected function user(string $name = 'Jane'): User
    {
        return User::query()->create(['name' => $name, 'email' => strtolower($name).'-'.uniqid().'@example.com']);
    }

    protected function admin(string $name = 'Admin'): Admin
    {
        return Admin::query()->create(['name' => $name, 'email' => strtolower($name).'-'.uniqid().'@example.com']);
    }
}
