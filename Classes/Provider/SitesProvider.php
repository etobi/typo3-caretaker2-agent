<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Which sites and domains live in this instance.
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
                'locale' => (string)$language->getLocale(),
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

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
