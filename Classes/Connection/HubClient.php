<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Die einzige Stelle, an der der Agent nach außen spricht.
 *
 * Immer der Agent zum Hub, nie umgekehrt — deshalb funktioniert das auch
 * hinter Firewalls, und deshalb muss die Instanz keinen Endpunkt
 * exponieren.
 */
final class HubClient
{
    private const TIMEOUT_SECONDS = 20;

    /** Ein eigener Prefix, damit die API nicht mit einer echten Seite kollidiert. */
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
     * Tauscht den kurzlebigen Enrollment-Code gegen ein dauerhaftes Token
     * und speichert es. Der Code ist damit verbraucht.
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
                'Der Hub hat kein Token zurückgegeben. Ist der Code noch gültig?'
            );
        }

        $this->tokenStorage->store($hubUrl, $token);

        return $token;
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed> Antwort des Hubs
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
            // Bewusst im Header und nicht im Query-String: Query-Strings
            // stehen in Access-Logs, Proxy-Logs und Auswertungswerkzeugen.
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
                sprintf('Hub nicht erreichbar (%s): %s', $url, $e->getMessage()),
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
}
