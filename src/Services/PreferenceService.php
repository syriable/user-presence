<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * Small, application-defined availability or activity preferences, for
 * example "show_online_status" => false or "working_hours" => "09:00-17:00".
 *
 * Values must be JSON serializable. The package does not interpret them.
 */
final readonly class PreferenceService
{
    public function __construct(private PresenceRepository $records) {}

    /**
     * @return array<string, mixed>
     */
    public function all(Model $user): array
    {
        return $this->records->find($user)->preferences ?? [];
    }

    public function get(Model $user, string $key, mixed $default = null): mixed
    {
        return $this->all($user)[$key] ?? $default;
    }

    /**
     * Set a preference. A null value removes it.
     */
    public function set(Model $user, string $key, mixed $value): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1) {
            throw new InvalidArgumentException("The preference key [{$key}] is invalid.");
        }

        if ($value !== null && ! is_scalar($value) && ! is_array($value)) {
            throw new InvalidArgumentException("The preference [{$key}] must be a scalar, an array or null.");
        }

        $preferences = $this->all($user);

        if ($value === null) {
            unset($preferences[$key]);
        } else {
            $preferences[$key] = $value;
        }

        $this->records->write($user, ['preferences' => $preferences === [] ? null : $preferences]);
    }
}
