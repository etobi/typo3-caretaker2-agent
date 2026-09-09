<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Controller;

use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\TokenStorage;
use Caretaker2\Agent\Inventory\InventoryBuilder;
use Caretaker2\Agent\Scheduler\SchedulerTaskException;
use Caretaker2\Agent\Scheduler\SchedulerTaskInstaller;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ConnectionController
{
    private const LL = 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:';

    /** @var ModuleTemplateFactory */
    private $moduleTemplateFactory;

    /** @var TokenStorage */
    private $tokenStorage;

    /** @var HubClient */
    private $hubClient;

    /** @var InventoryBuilder */
    private $inventoryBuilder;

    /** @var SchedulerTaskInstaller */
    private $scheduler;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        TokenStorage $tokenStorage,
        HubClient $hubClient,
        InventoryBuilder $inventoryBuilder,
        SchedulerTaskInstaller $scheduler
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->tokenStorage = $tokenStorage;
        $this->hubClient = $hubClient;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->scheduler = $scheduler;
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2');

        $message = null;
        $messageSeverity = 'info';
        $body = $request->getParsedBody();

        if ($request->getMethod() === 'POST' && is_array($body)) {
            [$message, $messageSeverity] = $this->handlePost($body, $request);
        }

        $inventory = $this->inventoryBuilder->build();

        $variables = [
            'connected' => $this->tokenStorage->isConnected(),
            'hubUrl' => $this->tokenStorage->getHubUrl(),
            'managedByEnvironment' => $this->tokenStorage->isManagedByEnvironment(),
            'agentVersion' => InventoryBuilder::AGENT_VERSION,
            'providers' => $this->describeProviders($inventory),
            'inventoryJson' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            'suggestedHubUrl' => $this->tokenStorage->getHubUrl(),
            'schedulerAvailable' => $this->scheduler->isAvailable(),
            'schedulerTaskExists' => $this->scheduler->exists(),
            'pushCommand' => SchedulerTaskInstaller::COMMAND,
        ];

        return $this->render($view, $variables);
    }

    /**
     * ModuleTemplate::renderResponse() arrived in v12. On v11 the template has
     * to be rendered separately and handed to the module as content — the only
     * difference in this controller across the supported versions.
     *
     * @param array<string, mixed> $variables
     */
    private function render(ModuleTemplate $view, array $variables): ResponseInterface
    {
        if (method_exists($view, 'renderResponse')) {
            $view->assignMultiple($variables);

            return $view->renderResponse('Connection/Index');
        }

        // A shell of our own without <f:layout name="Module">: that layout does
        // not exist on v11, and shipping one under the same name would displace
        // the core's on v12 and v13. The content itself lives in the same
        // partial for both paths.
        $standalone = GeneralUtility::makeInstance(\TYPO3\CMS\Fluid\View\StandaloneView::class);
        $standalone->setTemplateRootPaths(['EXT:caretaker2_agent/Resources/Private/Templates/']);
        $standalone->setPartialRootPaths(['EXT:caretaker2_agent/Resources/Private/Partials/']);
        $standalone->setTemplate('Connection/IndexV11');
        $standalone->assignMultiple($variables);

        $view->setContent($standalone->render());

        return new HtmlResponse($view->renderContent());
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string|null, 1: string}
     */
    private function handlePost(array $body, ServerRequestInterface $request): array
    {
        if (isset($body['disconnect'])) {
            if ($this->tokenStorage->isManagedByEnvironment()) {
                return [$this->ll('message.disconnectManaged'), 'warning'];
            }
            $this->tokenStorage->forget();

            return [$this->ll('message.disconnected'), 'info'];
        }

        if (isset($body['push'])) {
            return $this->push();
        }

        if (isset($body['installTask'])) {
            try {
                $this->scheduler->install();
            } catch (SchedulerTaskException $e) {
                return [$e->getMessage(), 'warning'];
            }

            return [$this->ll('message.taskCreated'), 'success'];
        }

        if (!isset($body['connect'])) {
            return [null, 'info'];
        }

        $hubUrl = trim((string)($body['hubUrl'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        if ($hubUrl === '' || $code === '') {
            return [$this->ll('message.credentialsMissing'), 'danger'];
        }

        try {
            $this->hubClient->enroll($hubUrl, $code, $this->currentBaseUrl($request));
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger'];
        }

        try {
            $this->hubClient->pushInventory($this->inventoryBuilder->build());
        } catch (HubConnectionException $e) {
            return [$this->ll('message.connectedPushFailed', $e->getMessage()), 'warning'];
        }

        return [$this->ll('message.connected'), 'success'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function push(): array
    {
        try {
            $response = $this->hubClient->pushInventory($this->inventoryBuilder->build());
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger'];
        }

        return [
            ($response['stored'] ?? false)
                ? $this->ll('message.pushedStored')
                : $this->ll('message.pushedUnchanged'),
            'success',
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @return list<array<string, string>>
     */
    private function describeProviders(array $inventory): array
    {
        $out = [];

        foreach ($inventory['providers'] as $key => $result) {
            $status = $result->getStatus();
            $out[] = [
                'key' => (string)$key,
                'status' => $status,
                'severity' => $status === 'ok' ? 'success' : ($status === 'degraded' ? 'warning' : 'danger'),
            ];
        }

        return $out;
    }

    private function currentBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $base .= ':' . $uri->getPort();
        }

        return $base;
    }

    /**
     * @param string|int ...$args
     */
    private function ll(string $key, ...$args): string
    {
        $text = $GLOBALS['LANG']->sL(self::LL . $key);

        return $args === [] ? $text : vsprintf($text, $args);
    }
}
