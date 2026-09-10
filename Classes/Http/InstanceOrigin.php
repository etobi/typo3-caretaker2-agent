<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Http;

use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The address this instance is reached under: TYPO3_BASE_URL when set,
 * otherwise the first site whose base names a host.
 */
final class InstanceOrigin
{
    /** @var SiteFinder */
    private $siteFinder;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    /**
     * Scheme, host and port, or null when nothing in the installation says
     * where it lives.
     */
    public function find(): ?string
    {
        $configured = getenv('TYPO3_BASE_URL');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($sites as $site) {
            if ($site->getBase()->getHost() !== '') {
                return Origin::fromUri($site->getBase());
            }
        }

        return null;
    }
}
