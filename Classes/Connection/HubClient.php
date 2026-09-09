<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use Caretaker2\Agent\Inventory\InventoryBuilder;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The only place where the agent speaks to the outside.
 *
 * Its messages are English literals on purpose: they travel back to the hub
 * through the trigger endpoint, and that runs in a frontend request where no
 * language service exists.
 */
final class HubClient
{
    private const TIMEOUT_SECONDS = 20;

    private const API_BASE = '/caretaker2/api';

    /** @var RequestFactory */
    private $requestFactory;

    /** @var TokenStorage */
    private $tokenStorage;

    public function __construct(RequestFactory $requestFactory, TokenStorage $tokenStorage)
    {
        $this->requestFactory = $requestFactory;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Trades the short-lived enrollment code for a lasting token
     */
    public function enroll(string $hubUrl, string $code, string $instanceUrl): string
    {
        $hubUrl = rtrim(trim($hubUrl), '/');

        $response = $this->send($hubUrl . self::API_BASE . '/enroll', [
            'code' => strtoupper(trim($code)),
            'instanceUrl' => $instanceUrl,
            'agentVersion' => InventoryBuilder::AGENT_VERSION,
        ]);

        $token = $response['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HubConnectionException('The hub returned no token. Is the code still valid?');
        }

        $this->tokenStorage->store($hubUrl, $token);

        return $token;
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    public function pushInventory(array $inventory): array
    {
        $hubUrl = $this->tokenStorage->getHubUrl();
        $token = $this->tokenStorage->getToken();

        if ($hubUrl === null || $token === null) {
            throw new HubConnectionException(
                'This instance is not connected to a hub. Connect it in the "Caretaker2" '
                . 'backend module, or set CARETAKER2_HUB_URL and CARETAKER2_TOKEN.'
            );
        }

        return $this->send($hubUrl . self::API_BASE . '/inventory', $inventory, $token);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $url, array $payload, ?string $token = null): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Caretaker2-Agent/' . InventoryBuilder::AGENT_VERSION,
        ];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'headers' => $headers,
                'body' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new HubConnectionException(
                sprintf('The hub is unreachable (%s): %s', $url, $e->getMessage()),
                0,
                $e
            );
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $detail = is_array($decoded) && isset($decoded['error'])
                ? (string)$decoded['error']
                : substr($body, 0, 200);

            throw new HubConnectionException(
                sprintf('The hub answered with HTTP %d: %s', $status, $detail)
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
