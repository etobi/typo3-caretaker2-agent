<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The only place where the agent speaks to the outside.
 */
final class HubClient
{
    private const LL = 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:';

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
            'agentVersion' => \Caretaker2\Agent\Inventory\InventoryBuilder::AGENT_VERSION,
        ]);

        $token = $response['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HubConnectionException(
                $this->ll('error.noToken')
            );
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
                'Diese Instanz ist mit keinem Hub verbunden. Im Backend-Modul '
                . '"Caretaker2" verbinden oder CARETAKER2_HUB_URL und '
                . 'CARETAKER2_TOKEN setzen.'
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
            'User-Agent' => 'Caretaker2-Agent/' . \Caretaker2\Agent\Inventory\InventoryBuilder::AGENT_VERSION,
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
                $this->ll('error.hubUnreachable', $url, $e->getMessage()),
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
                sprintf('Hub antwortet mit HTTP %d: %s', $status, $detail)
            );
        }

        return is_array($decoded) ? $decoded : [];
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
