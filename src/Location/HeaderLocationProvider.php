<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Location;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Syriable\UserPresence\Contracts\LocationProvider;
use Syriable\UserPresence\Data\Location;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Support\PresenceConfig;

/**
 * Reads the visitor location headers added by a CDN or reverse proxy, such as
 * Cloudflare (CF-IPCountry, CF-IPCity) or CloudFront (CloudFront-Viewer-*).
 *
 * Free and local: no external request is made. Only use it behind a proxy
 * that sets these headers itself, otherwise clients can spoof them. The
 * result is an IP-based estimate, not the user's residence.
 */
final readonly class HeaderLocationProvider implements LocationProvider
{
    public function __construct(private PresenceConfig $config) {}

    public function locate(Model $user, Request $request): ?Location
    {
        $location = new Location(
            city: $this->header($request, 'city'),
            region: $this->header($request, 'region'),
            countryCode: $this->header($request, 'country'),
            source: ContextSource::Provider,
        );

        return $location->isEmpty() ? null : $location;
    }

    private function header(Request $request, string $field): ?string
    {
        foreach ($this->config->locationHeaders($field) as $header) {
            $value = $request->headers->get($header);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
