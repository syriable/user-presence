<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Syriable\UserPresence\Casts\UtcDateTime;
use Syriable\UserPresence\Models\PresenceRecord;

/**
 * Reads and writes a user's presence record.
 *
 * Writes are single atomic upserts that only touch the given columns, so
 * concurrent heartbeats and preference updates never overwrite each other.
 *
 * @internal
 */
final readonly class PresenceRepository
{
    public const string RELATION = 'presenceRecord';

    public function __construct(private PresenceConfig $config) {}

    /**
     * Returns the eager-loaded record when available, otherwise queries it.
     */
    public function find(Model $user): ?PresenceRecord
    {
        if ($user->relationLoaded(self::RELATION)) {
            $record = $user->getRelation(self::RELATION);

            return $record instanceof PresenceRecord ? $record : null;
        }

        if ($user->getKey() === null) {
            return null;
        }

        return $this->query()
            ->where('presenceable_type', $user->getMorphClass())
            ->where('presenceable_id', $user->getKey())
            ->first();
    }

    /**
     * Run a callback with the user's record loaded once, so that several
     * reads in a row share a single query.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function remember(Model $user, callable $callback): mixed
    {
        if ($user->relationLoaded(self::RELATION)) {
            return $callback();
        }

        $user->setRelation(self::RELATION, $this->find($user));

        try {
            return $callback();
        } finally {
            $user->unsetRelation(self::RELATION);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function write(Model $user, array $attributes): void
    {
        if ($attributes === [] || $user->getKey() === null) {
            return;
        }

        $values = [
            'presenceable_type' => $user->getMorphClass(),
            'presenceable_id' => $user->getKey(),
            ...array_map($this->serialize(...), $attributes),
        ];

        $this->query()->upsert(
            [$values],
            ['presenceable_type', 'presenceable_id'],
            array_keys($attributes),
        );

        $user->unsetRelation(self::RELATION);
    }

    public function delete(Model $user): void
    {
        if ($user->getKey() === null) {
            return;
        }

        $this->query()
            ->where('presenceable_type', $user->getMorphClass())
            ->where('presenceable_id', $user->getKey())
            ->delete();

        $user->unsetRelation(self::RELATION);
    }

    /**
     * @return Builder<PresenceRecord>
     */
    public function query(): Builder
    {
        $model = $this->config->model();

        return $model::query();
    }

    private function serialize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => UtcDateTime::toStorage($value),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }
}
