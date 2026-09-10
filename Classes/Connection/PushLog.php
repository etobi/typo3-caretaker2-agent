<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use TYPO3\CMS\Core\Registry;

/**
 * What the last push delivered, per provider. The backend module shows it
 * for the providers it does not run itself while a page is loading.
 */
final class PushLog
{
    private const REGISTRY_NAMESPACE = 'caretaker2_agent';
    private const REGISTRY_KEY = 'lastPush';

    /** @var Registry */
    private $registry;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $response
     */
    public function record(array $inventory, array $response): void
    {
        $providers = [];
        foreach ($inventory['providers'] ?? [] as $key => $result) {
            $serialized = $result instanceof \JsonSerializable ? $result->jsonSerialize() : (array)$result;
            $providers[(string)$key] = [
                'status' => (string)($serialized['status'] ?? 'unavailable'),
                'reason' => (string)($serialized['reason'] ?? ''),
                'message' => (string)($serialized['message'] ?? ''),
            ];
        }

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'at' => time(),
            'stored' => (bool)($response['stored'] ?? false),
            'providers' => $providers,
        ]);
    }

    /**
     * @return array{at: int, stored: bool, providers: array<string, array{status: string, reason: string, message: string}>}|null
     */
    public function last(): ?array
    {
        $last = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);

        return is_array($last) && isset($last['at'], $last['providers']) ? $last : null;
    }
}
