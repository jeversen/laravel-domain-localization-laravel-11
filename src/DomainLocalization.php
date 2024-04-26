<?php

namespace Kevindierkx\LaravelDomainLocalization;

use Closure;
use Illuminate\Support\Str;
use Kevindierkx\LaravelDomainLocalization\Exceptions\InvalidUrlException;
use Kevindierkx\LaravelDomainLocalization\Exceptions\UnsupportedLocaleException;

class DomainLocalization
{
    use Concerns\HasLocaleConfigs;

    /**
     * The default app locale.
     *
     * @var string
     */
    protected $defaultLocale;

    /**
     * The callback for resolving the active locale.
     *
     * @var Closure
     */
    protected static $localeGetter;

    /**
     * The callback for setting the active locale.
     *
     * @var Closure
     */
    protected static $localeSetter;

    /**
     * The request instance used for resolving URIs and TLDs.
     *
     * @var \Illuminate\Http\Request
     */
    protected static $requestInstance;

    /**
     * Creates a new domain localization instance.
     *
     * @param string $defaultLocale
     * @param array  $locales
     */
    public function __construct(string $defaultLocale, array $locales)
    {
        $this->defaultLocale = $defaultLocale;

        foreach ($locales as $name => $config) {
            $this->addLocale((string) $name, $config);
        }

        if (empty($this->supportedLocales[$defaultLocale])) {
            throw new UnsupportedLocaleException(
                'The default locale is not configured in the `supported_locales` array.'
            );
        }
    }

    /**
     * Get the default application locale.
     *
     * @return string
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Get the active app locale.
     *
     * @return string
     */
    public function getCurrentLocale(): string
    {
        /** @var string */
        $configuredLocale = call_user_func(static::$localeGetter);

        return $configuredLocale;
    }

    /**
     * Set the active app locale.
     *
     * @param string $locale
     *
     * @return self
     */
    public function setCurrentLocale($locale): self
    {
        call_user_func(static::$localeSetter, $locale);

        return $this;
    }

    /**
     * Get top level domain.
     *
     * @param string $url
     *
     * @throws InvalidUrlException
     *
     * @return string
     */
    public function getTldFromUrl(string $url): string
    {
        if (! ($host = parse_url($url, PHP_URL_HOST))) {
            throw new InvalidUrlException(sprintf(
                'The url \'%s\' could not be parsed, make sure the provided URL contains a host.',
                $url
            ));
        }

        $matchingLocales = $this->resolveMatchingLocales($host);

        // When we don't match anything the locale might not be configured.
        // We will default to the last element after the final period.
        if (empty($matchingLocales)) {
            return sprintf('.%s', Str::afterLast($host, '.'));
        }

        return $matchingLocales[0];
    }

    /**
     * Resolve and sort matching TLDs from the config.
     * The best matching/longest will be first in the results.
     *
     * @param string $host
     *
     * @return array
     */
    protected function resolveMatchingLocales(string $host): array
    {
        $matches = [];

        foreach ($this->getSupportedLocales() as $config) {
            // We ensure the match is at the end of the string to prevent '.com'
            // being matched on '.com.dev'.
            if (
                isset($config['tld'])
                && strpos($host, $config['tld']) !== false
                && strlen($host) - strlen($config['tld']) === strrpos($host, $config['tld'])
            ) {
                $matches[] = $config['tld'];
            }
        }

        // The best matching TLD will most likely be the longest, before we
        // return the matches we sort them on size.
        usort($matches, [$this, 'compareStrLength']);

        return $matches;
    }

    /**
     * Resolve the length difference of two strings, used in the getTld method
     * for comparing the best matching TLD. Negative results would push the
     * item to the start since the TLD would be longer.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
     */
    protected function compareStrLength(string $a, string $b): int
    {
        return strlen($b) - strlen($a);
    }

    /**
     * Localize the URL to the provided locale key or to the default locale when
     * no locale is provided.
     *
     * @param string      $url
     * @param string|null $key
     *
     * @throws UnsupportedLocaleException
     *
     * @return string
     */
    public function getLocalizedUrl(string $url, ?string $key = null): string
    {
        $key = $key ?: $this->getDefaultLocale();

        // We validate the supplied locale before we mutate the current URL
        // to make sure the locale exists and we don't return an invalid URL.
        if (! $this->hasSupportedLocale($key)) {
            throw new UnsupportedLocaleException(sprintf(
                'The locale \'%s\' is not in the `supported_locales` array.',
                $key
            ));
        }

        return str_replace(
            $this->getTldFromUrl($url),
            $this->getTldForLocale($key),
            $url
        );
    }

    /**
     * Set the locale getter closure.
     *
     * @param Closure $closure
     *
     * @return void
     */
    public static function setLocaleGetter(Closure $closure): void
    {
        static::$localeGetter = $closure;
    }

    /**
     * Set the locale setter closure.
     *
     * @param Closure $closure
     *
     * @return void
     */
    public static function setLocaleSetter(Closure $closure): void
    {
        static::$localeSetter = $closure;
    }
}
