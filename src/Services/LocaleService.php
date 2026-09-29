<?php

declare(strict_types=1);

namespace Syriable\UserPresence\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Syriable\UserPresence\Contracts\LocaleResolver;
use Syriable\UserPresence\Enums\ContextSource;
use Syriable\UserPresence\Events\LocaleChanged;
use Syriable\UserPresence\Exceptions\InvalidLocale;
use Syriable\UserPresence\Support\PresenceConfig;
use Syriable\UserPresence\Support\PresenceRepository;

/**
 * Interface locale (the language of the UI) and spoken languages
 * (the languages a person can communicate in) are kept strictly separate.
 *
 * Resolution order: stored value (explicit choice first, then browser
 * detected), registered resolvers, configured default.
 */
final class LocaleService
{
    private const string LANGUAGE_TAG = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i';

    /** @var list<class-string<LocaleResolver>|LocaleResolver> */
    private array $resolvers = [];

    public function __construct(
        private readonly PresenceConfig $config,
        private readonly PresenceRepository $records,
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<LocaleResolver>|LocaleResolver  $resolver
     */
    public function registerResolver(string|LocaleResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    public function locale(Model $user): string
    {
        if (! $this->config->localeEnabled()) {
            return $this->defaultLocale();
        }

        $stored = $this->normalize($this->records->find($user)?->locale);

        if ($stored !== null) {
            return $stored;
        }

        foreach ($this->resolvers() as $resolver) {
            $resolved = $this->normalize($resolver->resolve($user));

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->defaultLocale();
    }

    /**
     * Where the stored locale came from, or null when none is stored.
     */
    public function source(Model $user): ?ContextSource
    {
        return $this->config->localeEnabled() ? $this->records->find($user)?->locale_source : null;
    }

    /**
     * Save the user's chosen interface locale. Pass null to clear it.
     *
     * @throws InvalidLocale
     */
    public function setLocale(Model $user, ?string $locale): void
    {
        if (! $this->config->localeEnabled()) {
            return;
        }

        if ($locale !== null) {
            $locale = $this->normalize($locale) ?? throw $this->invalid($locale);
        }

        $this->store($user, $locale, $locale === null ? null : ContextSource::User);
    }

    /**
     * Remember a locale reported by the browser. Ignored when the user has
     * chosen a locale themselves or the value is not supported.
     */
    public function recordDetected(Model $user, string $locale): bool
    {
        if (! $this->config->localeEnabled() || ! $this->config->detectLocale()) {
            return false;
        }

        $locale = $this->normalize($locale);
        $record = $this->records->find($user);

        if ($locale === null || $record?->locale_source === ContextSource::User) {
            return false;
        }

        if ($record?->locale !== $locale) {
            $this->store($user, $locale, ContextSource::Browser);
        }

        return true;
    }

    /**
     * Detect a locale from the Accept-Language header, only for users
     * without a stored locale. Requires a list of supported locales.
     */
    public function detectFromRequest(Model $user, Request $request): bool
    {
        if (! $this->config->detectLocale() || $this->config->supportedLocales() === []) {
            return false;
        }

        if ($this->records->find($user)?->locale !== null) {
            return false;
        }

        $locale = $this->match($request->getLanguages());

        return $locale !== null && $this->recordDetected($user, $locale);
    }

    /**
     * @return list<string>
     */
    public function spokenLanguages(Model $user): array
    {
        if (! $this->config->localeEnabled()) {
            return [];
        }

        return $this->records->find($user)->spoken_languages ?? [];
    }

    /**
     * Save the languages the user speaks. These are independent of the
     * supported interface locales.
     *
     * @param  list<string>  $languages
     *
     * @throws InvalidLocale
     */
    public function setSpokenLanguages(Model $user, array $languages): void
    {
        if (! $this->config->localeEnabled()) {
            return;
        }

        $normalized = [];

        foreach ($languages as $language) {
            $tag = $this->wellFormed($language) ?? throw InvalidLocale::malformed($language);
            $normalized[strtolower($tag)] ??= $tag;
        }

        $this->records->write($user, ['spoken_languages' => $normalized === [] ? null : array_values($normalized)]);
    }

    /**
     * Normalize a locale to its configured spelling, or null when invalid
     * or not supported. "zh_cn" becomes "zh-CN" when "zh-CN" is supported.
     */
    public function normalize(?string $locale): ?string
    {
        $tag = $locale === null ? null : $this->wellFormed($locale);

        if ($tag === null) {
            return null;
        }

        $supported = $this->config->supportedLocales();

        if ($supported === []) {
            return $tag;
        }

        foreach ($supported as $candidate) {
            if (strcasecmp($this->canonical($candidate), $tag) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    public function isSupported(string $locale): bool
    {
        return $this->normalize($locale) !== null;
    }

    public function defaultLocale(): string
    {
        return $this->config->defaultLocale();
    }

    /**
     * Pick the best supported locale for a list of preferred language tags,
     * falling back from region-specific tags ("de-AT") to the language ("de").
     *
     * @param  array<int, string>  $preferred
     */
    public function match(array $preferred): ?string
    {
        foreach ($preferred as $language) {
            $exact = $this->normalize($language);

            if ($exact !== null) {
                return $exact;
            }

            $primary = $this->normalize(strtok($this->canonical($language), '-') ?: null);

            if ($primary !== null) {
                return $primary;
            }
        }

        return null;
    }

    private function store(Model $user, ?string $locale, ?ContextSource $source): void
    {
        $previous = $this->records->find($user)?->locale;

        $this->records->write($user, ['locale' => $locale, 'locale_source' => $source]);

        if ($previous !== $locale) {
            LocaleChanged::dispatch($user, $locale, $previous, $source);
        }
    }

    private function wellFormed(string $locale): ?string
    {
        $tag = $this->canonical(trim($locale));

        return preg_match(self::LANGUAGE_TAG, $tag) === 1 && strlen($tag) <= 35 ? $tag : null;
    }

    private function canonical(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    private function invalid(string $locale): InvalidLocale
    {
        return $this->wellFormed($locale) === null
            ? InvalidLocale::malformed($locale)
            : InvalidLocale::unsupported($locale);
    }

    /**
     * @return iterable<LocaleResolver>
     */
    private function resolvers(): iterable
    {
        foreach ([...$this->config->localeResolvers(), ...$this->resolvers] as $resolver) {
            $resolver = is_string($resolver) ? $this->container->make($resolver) : $resolver;

            if ($resolver instanceof LocaleResolver) {
                yield $resolver;
            }
        }
    }
}
