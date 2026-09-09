<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Which sites and domains live in this instance.
 *
 * The domain list is the union of the site bases and every language base, not
 * just the site bases: a language may carry its own domain, which is exactly
 * the one that gets forgotten when a security advisory has to be turned into
 * a list of customers to call.
 */
final class SitesProvider implements ProviderInterface
{
    /** @var SiteFinder */
    private $siteFinder;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    public function getKey(): string
    {
        return 'sites';
    }

    public function collect(): ProviderResult
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable $e) {
            return ProviderResult::unavailable(
                'site_configuration_unreadable',
                sprintf('%s: %s', get_class($e), $e->getMessage())
            );
        }

        $entries = [];
        $hosts = [];

        foreach ($sites as $site) {
            $entry = $this->describeSite($site);
            $entries[] = $entry;

            foreach ($entry['hosts'] as $host) {
                $hosts[$host] = true;
            }
        }

        ksort($hosts);

        return ProviderResult::ok([
            'count' => count($entries),
            'sites' => $entries,
            'hosts' => array_keys($hosts),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSite(Site $site): array
    {
        $configuration = $site->getConfiguration();
        $base = (string)$site->getBase();
        $hosts = $this->hostOf($base) !== null ? [$this->hostOf($base)] : [];

        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $languageBase = (string)$language->getBase();
            $host = $this->hostOf($languageBase);
            if ($host !== null && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }

            $languages[] = [
                'languageId' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'locale' => $this->localeOf($language),
                'base' => $languageBase,
                'enabled' => $language->isEnabled(),
            ];
        }

        return [
            'identifier' => $site->getIdentifier(),
            'websiteTitle' => (string)($configuration['websiteTitle'] ?? ''),
            'rootPageId' => $site->getRootPageId(),
            'base' => $base,
            'languages' => $languages,
            'hosts' => $hosts,
        ];
    }

    /**
     * getLocale() returns a string up to v12 and a Locale object from v13 on.
     */
    private function localeOf(SiteLanguage $language): string
    {
        $locale = $language->getLocale();

        return is_object($locale) ? (string)$locale : (string)$locale;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
