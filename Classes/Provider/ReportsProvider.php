<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use Psr\Http\Message\ServerRequestInterface;
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

    private const MAX_MESSAGE_LENGTH = 2000;

    /** @var iterable<object> */
    private $statusProviders;

    /** @var ReportRequestFactory */
    private $requestFactory;

    /**
     * @param iterable<object> $statusProviders
     */
    public function __construct(iterable $statusProviders, ReportRequestFactory $requestFactory)
    {
        $this->statusProviders = $statusProviders;
        $this->requestFactory = $requestFactory;
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

        $request = $this->requestFactory->create();

        $previousLanguage = $GLOBALS['LANG'] ?? null;
        $this->useDefaultLanguage();

        try {
            [$issues, $checked, $skipped, $withoutRequest] = $this->collectStatuses($request);
        } finally {
            $GLOBALS['LANG'] = $previousLanguage;
        }

        $data = [
            'origin' => $request !== null ? rtrim((string)$request->getUri(), '/') : null,
            'checked' => $checked,
            'issueCount' => count($issues),
            'issues' => $issues,
            'skipped' => $skipped,
            'withoutRequest' => $withoutRequest,
        ];

        // The checks judge the runtime they happen to run in, so a scheduler
        // push and a hub-triggered one disagree about some of them. They
        // describe the current state, not a change to the installation.
        if ($skipped !== []) {
            return ProviderResult::degraded(
                $data,
                'provider_threw',
                sprintf('%d of TYPO3\'s own checks broke off unexpectedly.', count($skipped)),
                ['*']
            );
        }

        // A check that looks at the request and got none quietly leaves out
        // what it cannot judge, HTTPS and lockSSL among it. That is a gap,
        // and it is named rather than passed off as an all-clear.
        if ($withoutRequest !== []) {
            return ProviderResult::degraded(
                $data,
                'no_request',
                sprintf(
                    '%d of TYPO3\'s own checks look at the request and ran without one, so the HTTPS checks are missing. Set TYPO3_BASE_URL or give a site an absolute base URL.',
                    count($withoutRequest)
                ),
                ['*']
            );
        }

        return ProviderResult::ok($data, ['*']);
    }

    /**
     * @return array{0: list<array<string, string>>, 1: int, 2: list<array<string, string>>, 3: list<string>}
     */
    private function collectStatuses(?ServerRequestInterface $request): array
    {
        $issues = [];
        $checked = 0;
        $skipped = [];
        $withoutRequest = [];

        foreach ($this->allStatusProviders() as $entry) {
            $provider = $entry['provider'];
            $wantsRequest = $provider instanceof RequestAwareStatusProviderInterface;

            if ($wantsRequest && $request === null) {
                $withoutRequest[] = get_class($provider);
            }

            try {
                $statuses = $wantsRequest ? $provider->getStatus($request) : $provider->getStatus();
            } catch (\Throwable $e) {
                // Named rather than swallowed: the hub must be able to see what
                // was not looked at.
                $skipped[] = [
                    'provider' => get_class($provider),
                    'reason' => $wantsRequest && $request === null ? 'requires_request' : 'threw',
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

        return [$issues, $checked, $skipped, $withoutRequest];
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

    /**
     * The message as text, with what the markup said kept in words: a line
     * per paragraph or list item, a dash in front of list items, the
     * address next to a link's label. No tag survives, so the hub can show
     * it escaped without losing the shape.
     */
    private function plainText(string $message): string
    {
        $text = $message;

        // A button belongs to the reports module; its label alone is noise.
        $text = (string)preg_replace('/<button\b[^>]*>.*?<\/button>/is', '', $text);

        $text = (string)preg_replace_callback(
            '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            static function (array $match): string {
                $href = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = trim(strip_tags($match[3]));

                if (stripos($href, 'http://') !== 0 && stripos($href, 'https://') !== 0) {
                    return $label;
                }

                return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
            },
            $text
        );

        $text = (string)preg_replace('/<li\b[^>]*>/i', "\n- ", $text);
        $text = (string)preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = (string)preg_replace('/<\/(p|div|li|ul|ol|h[1-6]|tr|table|blockquote|pre)>/i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = trim((string)preg_replace('/\n{2,}/', "\n", $text));

        return mb_strlen($text) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($text, 0, self::MAX_MESSAGE_LENGTH) . '…'
            : $text;
    }
}
