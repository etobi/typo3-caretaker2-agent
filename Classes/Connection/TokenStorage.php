<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use TYPO3\CMS\Core\Registry;

/**
 * The hub address and the token.
 *
 * Two sources, on purpose: sys_registry can be maintained from the backend
 * module without a deployment, but the environment always wins. That is the
 * point — a production database copied to staging brings its token along, and
 * without the environment taking precedence the staging copy would start
 * reporting as production.
 */
final class TokenStorage
{
    private const NAMESPACE = 'caretaker2_agent';

    private const ENV_HUB_URL = 'CARETAKER2_HUB_URL';
    private const ENV_TOKEN = 'CARETAKER2_TOKEN';

    /** @var Registry */
    private $registry;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function getHubUrl(): ?string
    {
        return $this->readEnv(self::ENV_HUB_URL)
            ?? $this->normalizeString($this->registry->get(self::NAMESPACE, 'hubUrl'));
    }

    public function getToken(): ?string
    {
        return $this->readEnv(self::ENV_TOKEN)
            ?? $this->normalizeString($this->registry->get(self::NAMESPACE, 'token'));
    }

    public function isConnected(): bool
    {
        return $this->getHubUrl() !== null && $this->getToken() !== null;
    }

    public function isManagedByEnvironment(): bool
    {
        return $this->readEnv(self::ENV_TOKEN) !== null;
    }

    public function store(string $hubUrl, string $token): void
    {
        $this->registry->set(self::NAMESPACE, 'hubUrl', rtrim($hubUrl, '/'));
        $this->registry->set(self::NAMESPACE, 'token', $token);
    }

    public function forget(): void
    {
        $this->registry->remove(self::NAMESPACE, 'hubUrl');
        $this->registry->remove(self::NAMESPACE, 'token');
    }

    private function readEnv(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? null;
        }

        return $this->normalizeString($value);
    }

    /**
     * @param mixed $value
     */
    private function normalizeString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
