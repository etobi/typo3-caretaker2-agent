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

/**
 * Das ganze Setup der Instanz: zwei Felder und ein Knopf.
 *
 * Der Vorgänger brauchte hier den Austausch zweier Schlüsselpaare. Genau
 * daran ist er gescheitert — was zu umständlich ist, wird nicht ausgerollt.
 */
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

        // Eigene Hülle ohne <f:layout name="Module">: Das Layout gibt es in v11
        // nicht, und ein gleichnamiges mitzuliefern würde in v12 und v13 das
        // des Cores verdrängen. Der Inhalt selbst liegt für beide Wege im
        // selben Partial.
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
                return ['Die Verbindung kommt aus der Umgebung und kann hier nicht getrennt werden.', 'warning'];
            }
            $this->tokenStorage->forget();

            return ['Verbindung getrennt.', 'info'];
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

            return ['Der tägliche Scheduler-Task ist angelegt.', 'success'];
        }

        if (!isset($body['connect'])) {
            return [null, 'info'];
        }

        $hubUrl = trim((string)($body['hubUrl'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        if ($hubUrl === '' || $code === '') {
            return ['Hub-Adresse und Code werden beide gebraucht.', 'danger'];
        }

        try {
            $this->hubClient->enroll($hubUrl, $code, $this->currentBaseUrl($request));
        } catch (HubConnectionException $e) {
            return [$e->getMessage(), 'danger'];
        }

        // Sofort melden, damit die Instanz im Hub nicht als leerer Platzhalter
        // erscheint, sondern gleich mit Daten.
        try {
            $this->hubClient->pushInventory($this->inventoryBuilder->build());
        } catch (HubConnectionException $e) {
            return ['Verbunden, aber der erste Push ist fehlgeschlagen: ' . $e->getMessage(), 'warning'];
        }

        return ['Verbunden. Das Inventar wurde bereits gemeldet.', 'success'];
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

        // The hub only stores a snapshot when the fingerprint changed. Saying
        // so avoids the impression that nothing happened.
        return [
            ($response['stored'] ?? false)
                ? 'Inventar gemeldet. Der Hub hat eine Veränderung gespeichert.'
                : 'Inventar gemeldet. Es hat sich nichts geändert, der Hub speichert daher keinen neuen Stand.',
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
}
