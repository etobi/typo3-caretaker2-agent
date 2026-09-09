<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\RequestAwareStatusProviderInterface;

/**
 * Everything TYPO3 already checks about itself.
 *
 * The reports framework is where the core and any number of extensions publish
 * their own health checks: install tool password, devIPmask, displayErrors,
 * file permissions, database analysis. Reading it is a fraction of the work of
 * reimplementing those checks, and the list grows on its own whenever TYPO3
 * adds one or the customer installs an extension that brings its own.
 *
 * Only statuses above OK are reported. How many were looked at is reported too,
 * so the hub can tell "nothing wrong" from "nothing checked".
 *
 * The status providers are collected straight from the reports.status DI tag
 * rather than through StatusRegistry, which is a private service and cannot be
 * reached from another extension in v14. Taking the tag instead also means the
 * agent carries no build-time dependency on the reports extension: without it
 * the iterator is simply empty.
 *
 * No request is passed, deliberately. A check that needs one describes the
 * context that asked rather than the instance, and would answer differently
 * depending on whether the scheduler or the hub triggered the collection — the
 * same churn the fingerprint had to be normalised against. TYPO3's own
 * ServerResponseCheck is the case in point: it issues outgoing HTTP requests
 * against the site, which is infrastructure and out of scope by decision C6.
 * Providers that insist on a request are skipped and named.
 *
 * Every provider reaches for $GLOBALS['LANG'] without checking it. The CLI
 * context sets it up, a frontend middleware does not, so a hub-triggered
 * collection would have lost almost every check. It is therefore set here for
 * the duration — and always to the default locale, so that the wording of the
 * messages cannot depend on who triggered the collection or on a backend
 * user's language setting. Both would move the fingerprint.
 */
final class ReportsProvider implements ProviderInterface
{
    private const SEVERITY_LABELS = [
        1 => 'warning',
        2 => 'error',
    ];

    private const MAX_MESSAGE_LENGTH = 500;

    /** @var iterable<object> */
    private $statusProviders;

    /**
     * @param iterable<object> $statusProviders
     */
    public function __construct(iterable $statusProviders)
    {
        $this->statusProviders = $statusProviders;
    }

    public function getKey(): string
    {
        return 'reports';
    }

    public function collect(): ProviderResult
    {
        if (!ExtensionManagementUtility::isLoaded('reports')) {
            return ProviderResult::unavailable(
                'reports_not_installed',
                'Die Extension "reports" ist nicht installiert. Ohne sie führt TYPO3 seine eigenen Prüfungen nicht aus.'
            );
        }

        $issues = [];
        $checked = 0;
        $skipped = [];

        $previousLanguage = $GLOBALS['LANG'] ?? null;
        $this->useDefaultLanguage();

        try {
            [$issues, $checked, $skipped] = $this->collectStatuses();
        } finally {
            $GLOBALS['LANG'] = $previousLanguage;
        }

        $data = [
            'checked' => $checked,
            'issueCount' => count($issues),
            'issues' => $issues,
            'skipped' => $skipped,
        ];

        // A provider that insists on a request is a deliberate omission, not a
        // gap: those checks are out of scope. Anything else that throws is a
        // gap, and says so.
        $unexpected = array_filter($skipped, static function (array $entry): bool {
            return $entry['reason'] !== 'requires_request';
        });

        if ($unexpected !== []) {
            return ProviderResult::degraded(
                $data,
                'provider_threw',
                sprintf('%d von TYPO3s eigenen Prüfungen brachen unerwartet ab.', count($unexpected))
            );
        }

        return ProviderResult::ok($data);
    }

    /**
     * @return array{0: list<array<string, string>>, 1: int, 2: list<array<string, string>>}
     */
    private function collectStatuses(): array
    {
        $issues = [];
        $checked = 0;
        $skipped = [];

        foreach ($this->allStatusProviders() as $entry) {
            $provider = $entry['provider'];

            try {
                $statuses = $provider->getStatus();
            } catch (\Throwable $e) {
                // Named rather than swallowed: the hub must be able to see what
                // was not looked at.
                $skipped[] = [
                    'provider' => get_class($provider),
                    'reason' => $provider instanceof RequestAwareStatusProviderInterface
                        ? 'requires_request'
                        : 'threw',
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            foreach ($statuses as $status) {
                $checked++;
                $severity = $this->severityOf($status);

                if (!isset(self::SEVERITY_LABELS[$severity])) {
                    continue;
                }

                $issues[] = [
                    'provider' => $entry['label'] !== null ? $entry['label'] : $this->labelOf($provider),
                    'title' => (string)$status->getTitle(),
                    'value' => (string)$status->getValue(),
                    'severity' => self::SEVERITY_LABELS[$severity],
                    'message' => $this->plainText((string)$status->getMessage()),
                ];
            }
        }

        return [$issues, $checked, $skipped];
    }

    /**
     * The DI tag "reports.status" only exists from v12 on. v11 registers its
     * status providers in SC_OPTIONS, so an agent that only reads the tag
     * finds nothing there and reports "ok, zero checked" — which reads like an
     * all-clear and is the opposite of one.
     *
     * The array is keyed by section — "typo3", "security", "configuration" —
     * and that key is what v12 later turned into getLabel(). Carrying it along
     * makes a finding read the same on either version.
     *
     * @return list<array{provider: object, label: string|null}>
     */
    private function allStatusProviders(): array
    {
        $providers = [];
        foreach ($this->statusProviders as $provider) {
            $providers[] = ['provider' => $provider, 'label' => null];
        }

        if ($providers !== []) {
            return $providers;
        }

        $registered = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['reports']['tx_reports']['status']['providers'] ?? [];
        if (!is_array($registered)) {
            return $providers;
        }

        foreach ($registered as $section => $classNames) {
            foreach ((array)$classNames as $className) {
                if (!is_string($className) || !class_exists($className)) {
                    continue;
                }

                try {
                    $providers[] = [
                        'provider' => GeneralUtility::makeInstance($className),
                        'label' => (string)$section,
                    ];
                } catch (\Throwable $e) {
                    // A provider that cannot even be built is one we cannot ask.
                }
            }
        }

        return $providers;
    }

    /**
     * Always the default locale, never the current user's. The reports carry
     * translated text, and a message that reads differently depending on who
     * looked would count as a change in the hub.
     */
    private function useDefaultLanguage(): void
    {
        try {
            $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

            // v11 providers read their titles with getLL(), which answers only
            // once the file is loaded. TYPO3 does that in the report class we
            // bypass, so without this every v11 status arrives untitled.
            if (method_exists($GLOBALS['LANG'], 'includeLLFile')) {
                $GLOBALS['LANG']->includeLLFile('EXT:reports/Resources/Private/Language/locallang_reports.xlf');
            }
        } catch (\Throwable $e) {
            // Leave whatever was there. Providers that need it will report as
            // skipped, which is visible.
        }
    }

    /**
     * getSeverity() returns a ContextualFeedbackSeverity from v12 on and a
     * plain int before that.
     *
     * @param mixed $status
     */
    private function severityOf($status): int
    {
        $severity = $status->getSeverity();

        return $severity instanceof \BackedEnum ? (int)$severity->value : (int)$severity;
    }

    /**
     * @param mixed $provider
     */
    private function labelOf($provider): string
    {
        try {
            if (method_exists($provider, 'getLabel')) {
                return (string)$provider->getLabel();
            }
        } catch (\Throwable $e) {
            // fall through to the class name
        }

        return get_class($provider);
    }

    /**
     * Report messages carry markup and can run long.
     */
    private function plainText(string $message): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($message)));

        return mb_strlen($text) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($text, 0, self::MAX_MESSAGE_LENGTH) . '…'
            : $text;
    }
}
