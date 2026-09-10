<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use Caretaker2\Agent\AgentVersion;
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
     * Trades the short-lived enrollment code for a lasting token. Hubs that
     * sit behind HTTP Basic Auth take a user and password along; both are
     * kept with the token for every later push.
     */
    public function enroll(
        string $hubUrl,
        string $code,
        string $instanceUrl,
        string $hubUser = '',
        string $hubPassword = ''
    ): string {
        $hubUrl = rtrim(trim($hubUrl), '/');
        $auth = $hubUser === '' ? null : [$hubUser, $hubPassword];

        $response = $this->send($hubUrl . self::API_BASE . '/enroll', [
            'code' => strtoupper(trim($code)),
            'instanceUrl' => $instanceUrl,
            'agentVersion' => AgentVersion::current(),
        ], null, $auth);

        $token = $response['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HubConnectionException('The hub returned no token. Is the code still valid?');
        }

        $this->tokenStorage->store($hubUrl, $token, $hubUser, $hubPassword);

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

        $hubUser = $this->tokenStorage->getHubUser();
        $auth = $hubUser === null ? null : [$hubUser, $this->tokenStorage->getHubPassword()];

        return $this->send($hubUrl . self::API_BASE . '/inventory', $inventory, $token, $auth);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{0: string, 1: string}|null $auth Basic Auth user and password
     * @return array<string, mixed>
     */
    private function send(string $url, array $payload, ?string $token = null, ?array $auth = null): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Caretaker2-Agent/' . AgentVersion::current(),
        ];
        if ($token !== null) {
            // Basic Auth needs the Authorization header for itself, so the
            // token moves to a header of its own in that case.
            if ($auth === null) {
                $headers['Authorization'] = 'Bearer ' . $token;
            } else {
                $headers['X-Caretaker2-Token'] = $token;
            }
        }

        $options = [
            'headers' => $headers,
            'body' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
            'timeout' => self::TIMEOUT_SECONDS,
            'http_errors' => false,
        ];
        if ($auth !== null) {
            $options['auth'] = $auth;
        }

        try {
            $response = $this->requestFactory->request($url, 'POST', $options);
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

        if ($status === 401) {
            throw new HubConnectionException(
                $auth === null
                    ? 'The hub asks for HTTP Basic Auth. Enter the credentials when connecting.'
                    : 'The hub rejected the Basic Auth credentials.'
            );
        }

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
