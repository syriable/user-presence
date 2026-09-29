<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Syriable\UserPresence\Casts\UtcDateTime;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Support\PresenceConfig;

/**
 * @property int $id
 * @property string $presenceable_type
 * @property int|string $presenceable_id
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $last_logout_at
 * @property string|null $last_login_ip
 * @property string|null $locale
 * @property ContextSource|null $locale_source
 * @property list<string>|null $spoken_languages
 * @property string|null $timezone
 * @property ContextSource|null $timezone_source
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country_code
 * @property string|null $country_name
 * @property ContextSource|null $location_source
 * @property array<string, mixed>|null $preferences
 */
class PresenceRecord extends Model
{
    protected $guarded = [];

    protected $hidden = ['last_login_ip'];

    public function getTable(): string
    {
        return app(PresenceConfig::class)->tableName();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function presenceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => UtcDateTime::class,
            'last_activity_at' => UtcDateTime::class,
            'last_login_at' => UtcDateTime::class,
            'last_logout_at' => UtcDateTime::class,
            'locale_source' => ContextSource::class,
            'timezone_source' => ContextSource::class,
            'location_source' => ContextSource::class,
            'spoken_languages' => 'array',
            'preferences' => 'array',
        ];
    }
}
