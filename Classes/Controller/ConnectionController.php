<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Controller;

use Caretaker2\Agent\AgentVersion;
use Caretaker2\Agent\Backend\Labels;
use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\PushLog;
use Caretaker2\Agent\Connection\TokenStorage;
use Caretaker2\Agent\Http\Origin;
use Caretaker2\Agent\Inventory\InventoryBuilder;
use Caretaker2\Agent\Scheduler\SchedulerTaskException;
use Caretaker2\Agent\Scheduler\SchedulerTaskInstaller;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
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

    /** @var PushLog */
    private $pushLog;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        TokenStorage $tokenStorage,
        HubClient $hubClient,
        InventoryBuilder $inventoryBuilder,
        SchedulerTaskInstaller $scheduler,
        Labels $labels,
        PushLog $pushLog
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->tokenStorage = $tokenStorage;
        $this->hubClient = $hubClient;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->scheduler = $scheduler;
        $this->labels = $labels;
        $this->pushLog = $pushLog;
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2');

        $message = null;
        $messageSeverity = 'info';
        $inventory = null;
        $body = $request->getParsedBody();

        if ($request->getMethod() === 'POST' && is_array($body)) {
            [$message, $messageSeverity, $inventory] = $this->handlePost($body, $request);
        }

        // Collecting the inventory takes seconds, TYPO3's own checks call the
        // instance over HTTP a dozen times. So the page collects nothing of
        // its own: it shows what a push in this request delivered, otherwise
        // what the last push found.
        $lastPush = $inventory === null ? $this->pushLog->last() : null;

        $variables = [
            'connected' => $this->tokenStorage->isConnected(),
            'hubUrl' => $this->tokenStorage->getHubUrl(),
            'hubUser' => $this->tokenStorage->getHubUser(),
            'managedByEnvironment' => $this->tokenStorage->isManagedByEnvironment(),
            'agentVersion' => AgentVersion::current(),
            'providers' => $inventory !== null
                ? $this->describeProviders($inventory)
                : ($lastPush !== null ? $this->describeLastPush($lastPush) : []),
            'inventoryAsOf' => $lastPush !== null ? BackendUtility::datetime($lastPush['at']) : '',
            'inventoryJson' => $inventory !== null ? json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
            'message' => $message,
            'messageSeverity' => $messageSeverity,
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
     * Message, its severity, and the inventory when the action built one,
     * so the page does not build it a second time.
     *
     * @param array<string, mixed> $body
     * @return array{0: string|null, 1: string, 2: array<string, mixed>|null}
     */
    private function handlePost(array $body, ServerRequestInterface $request): array
    {
        if (isset($body['disconnect'])) {
            if ($this->tokenStorage->isManagedByEnvironment()) {
                return [$this->labels->get('message.disconnectManaged'), 'warning', null];
            }
            // The browser already insists on the checkbox; a request that
            // skipped it did not come through the form.
            if (($body['disconnectConfirmed'] ?? '') !== '1') {
                return [$this->labels->get('message.disconnectUnconfirmed'), 'warning', null];
            }
            $this->tokenStorage->forget();

            return [$this->labels->get('message.disconnected'), 'info', null];
        }

        if (isset($body['push'])) {
            return $this->push();
        }

        if (isset($body['installTask'])) {
            try {
                $this->scheduler->install();
            } catch (SchedulerTaskException $e) {
                return [$this->labels->get($e->labelKey, ...$e->labelArguments), 'warning', null];
            }

            return [$this->labels->get('message.taskCreated'), 'success', null];
        }

        if (isset($body['repairTask'])) {
            try {
                $this->scheduler->repair();
            } catch (SchedulerTaskException $e) {
                return [$this->labels->get($e->labelKey, ...$e->labelArguments), 'warning', null];
            }

            return [$this->labels->get('message.taskRepaired'), 'success', null];
        }

        if (!isset($body['connect'])) {
            return [null, 'info', null];
        }

        $hubUrl = trim((string)($body['hubUrl'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        if ($hubUrl === '' || $code === '') {
            return [$this->labels->get('message.credentialsMissing'), 'danger', null];
        }

        try {
            $this->hubClient->enroll(
                $hubUrl,
                $code,
                Origin::fromRequest($request),
                trim((string)($body['hubUser'] ?? '')),
                (string)($body['hubPassword'] ?? '')
            );
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger', null];
        }

        $inventory = $this->inventoryBuilder->build();

        try {
            $this->hubClient->pushInventory($inventory);
        } catch (HubConnectionException $e) {
            return [$this->labels->get('message.connectedPushFailed', $e->getMessage()), 'warning', $inventory];
        }

        return [$this->labels->get('message.connected'), 'success', $inventory];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function push(): array
    {
        $inventory = $this->inventoryBuilder->build();

        try {
            $response = $this->hubClient->pushInventory($inventory);
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger', $inventory];
        }

        return [
            ($response['stored'] ?? false)
                ? $this->labels->get('message.pushedStored')
                : $this->labels->get('message.pushedUnchanged'),
            'success',
            $inventory,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @return list<array<string, string>>
     */
    private function describeProviders(array $inventory): array
    {
        $rows = [];
        foreach ($inventory['providers'] as $key => $result) {
            $rows[] = $this->describeProvider((string)$key, $result->getStatus());
        }

        return $rows;
    }

    /**
     * @param array{providers: array<string, array{status: string}>} $lastPush
     * @return list<array<string, string>>
     */
    private function describeLastPush(array $lastPush): array
    {
        $rows = [];
        ksort($lastPush['providers']);
        foreach ($lastPush['providers'] as $key => $result) {
            $rows[] = $this->describeProvider((string)$key, $result['status']);
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function describeProvider(string $key, string $status): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'severity' => $status === 'ok' ? 'success' : ($status === 'degraded' ? 'warning' : 'danger'),
        ];
    }

}
