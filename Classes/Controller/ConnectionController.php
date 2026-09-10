<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Controller;

use Caretaker2\Agent\Backend\Labels;
use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\TokenStorage;
use Caretaker2\Agent\Http\Origin;
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

    /** @var Labels */
    private $labels;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        TokenStorage $tokenStorage,
        HubClient $hubClient,
        InventoryBuilder $inventoryBuilder,
        SchedulerTaskInstaller $scheduler,
        Labels $labels
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->tokenStorage = $tokenStorage;
        $this->hubClient = $hubClient;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->scheduler = $scheduler;
        $this->labels = $labels;
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
            'schedulerTaskRecurring' => $this->scheduler->isRecurring(),
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
                return [$this->labels->get('message.disconnectManaged'), 'warning'];
            }
            $this->tokenStorage->forget();

            return [$this->labels->get('message.disconnected'), 'info'];
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

            return [$this->labels->get('message.taskCreated'), 'success'];
        }

        if (isset($body['repairTask'])) {
            try {
                $this->scheduler->repair();
            } catch (SchedulerTaskException $e) {
                return [$e->getMessage(), 'warning'];
            }

            return [$this->labels->get('message.taskRepaired'), 'success'];
        }

        if (!isset($body['connect'])) {
            return [null, 'info'];
        }

        $hubUrl = trim((string)($body['hubUrl'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        if ($hubUrl === '' || $code === '') {
            return [$this->labels->get('message.credentialsMissing'), 'danger'];
        }

        try {
            $this->hubClient->enroll($hubUrl, $code, Origin::fromRequest($request));
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger'];
        }

        try {
            $this->hubClient->pushInventory($this->inventoryBuilder->build());
        } catch (HubConnectionException $e) {
            return [$this->labels->get('message.connectedPushFailed', $e->getMessage()), 'warning'];
        }

        return [$this->labels->get('message.connected'), 'success'];
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
                ? $this->labels->get('message.pushedStored')
                : $this->labels->get('message.pushedUnchanged'),
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

}
