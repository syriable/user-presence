<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Data;

use Syriable\UserPresence\Enums\ContextSource;

/**
 * A coarse, city-level location. Never contains coordinates.
 */
final readonly class Location
{
    public function __construct(
        public ?string $city = null,
        public ?string $region = null,
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ContextSource $source = ContextSource::User,
    ) {}

    public function isEmpty(): bool
    {
        return $this->city === null
            && $this->region === null
            && $this->countryCode === null
            && $this->countryName === null;
    }

    /**
     * Whether the location was detected rather than provided by the user.
     */
    public function isInferred(): bool
    {
        return $this->source->isInferred();
    }

    public function withSource(ContextSource $source): self
    {
        return new self($this->city, $this->region, $this->countryCode, $this->countryName, $source);
    }

    /**
     * @return array{city: ?string, region: ?string, country_code: ?string, country_name: ?string, source: string}
     */
    public function toArray(): array
    {
        return [
            'city' => $this->city,
            'region' => $this->region,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'source' => $this->source->value,
        ];
    }
}
