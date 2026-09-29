<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Syriable\UserPresence\Data\Presence;
use Syriable\UserPresence\Models\PresenceRecord;
use Syriable\UserPresence\Services\PresenceService;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\UserPresenceManager;

/**
 * Adds presence to an Eloquent model without touching its table.
 *
 * @mixin Model
 */
trait HasUserPresence
{
    public static function bootHasUserPresence(): void
    {
        static::deleted(static function (Model $model): void {
            if (! app(PresenceConfig::class)->deleteWithUser()) {
                return;
            }

            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            app(UserPresenceManager::class)->forget($model);
        });
    }

    /**
     * The stored presence record. Eager load it to avoid N+1 queries:
     * User::with('presenceRecord')->get().
     *
     * @return MorphOne<PresenceRecord, $this>
     */
    public function presenceRecord(): MorphOne
    {
        return $this->morphOne(app(PresenceConfig::class)->model(), 'presenceable');
    }

    /**
     * A snapshot of the user's presence and local context.
     */
    public function presence(): Presence
    {
        return app(UserPresenceManager::class)->get($this);
    }

    public function isOnline(): bool
    {
        return app(UserPresenceManager::class)->isOnline($this);
    }

    /**
     * Only users that are currently online. Requires the "database" presence store.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function online(Builder $query): void
    {
        $query->whereHas('presenceRecord', static function (Builder $query): void {
            app(PresenceService::class)->constrainToOnline($query);
        });
    }
}
