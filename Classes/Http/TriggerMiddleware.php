<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Http;

use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\TokenStorage;
use Caretaker2\Agent\Inventory\InventoryBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Registry;

/**
 * Lets the hub ask this instance to report right away.
 */
final class TriggerMiddleware implements MiddlewareInterface
{
    private const PATH = '/caretaker2/trigger';

    private const COOLDOWN_SECONDS = 30;

    private const REGISTRY_NAMESPACE = 'caretaker2_agent';
    private const REGISTRY_KEY = 'lastTrigger';

    /** @var TokenStorage */
    private $tokenStorage;

    /** @var HubClient */
    private $hubClient;

    /** @var InventoryBuilder */
    private $inventoryBuilder;

    /** @var Registry */
    private $registry;

    public function __construct(
        TokenStorage $tokenStorage,
        HubClient $hubClient,
        InventoryBuilder $inventoryBuilder,
        Registry $registry
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->hubClient = $hubClient;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->registry = $registry;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::PATH) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => 'POST required'], 405);
        }

        if (!$this->tokenStorage->isConnected()) {
            return new JsonResponse(['error' => 'Not connected to any hub'], 409);
        }

        $last = (int)$this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, 0);
        $wait = $last + self::COOLDOWN_SECONDS - time();
        if ($wait > 0) {
            return new JsonResponse(['error' => 'Cooldown', 'retryAfter' => $wait], 429);
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, time());

        try {
            $response = $this->hubClient->pushInventory($this->inventoryBuilder->build());
        } catch (HubConnectionException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        return new JsonResponse([
            'pushed' => true,
            'stored' => $response['stored'] ?? false,
        ], 202);
    }
}
