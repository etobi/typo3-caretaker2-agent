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
 * Everything TYPO3 already checks about itself: install tool password,
 * devIPmask, file permissions, whatever extensions add.
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
                'The reports extension is not installed. Without it TYPO3 does not run its own checks.'
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
                sprintf('%d of TYPO3\'s own checks broke off unexpectedly.', count($unexpected))
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
                    'provider' => $entry['label'] ?? $this->labelOf($provider),
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

    private function useDefaultLanguage(): void
    {
        try {
            $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

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
        }

        return get_class($provider);
    }

    private function plainText(string $message): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($message)));

        return mb_strlen($text) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($text, 0, self::MAX_MESSAGE_LENGTH) . '…'
            : $text;
    }
}
