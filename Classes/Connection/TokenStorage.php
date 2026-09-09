<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Connection;

use TYPO3\CMS\Core\Registry;

/**
 * Hub-Adresse und Token.
 *
 * Zwei Quellen, mit Absicht: sys_registry ist über das Backend-Modul
 * pflegbar, ohne Deployment. Umgebungsvariablen gewinnen aber immer —
 * und das ist der Punkt: Eine Produktionsdatenbank, die nach Staging
 * kopiert wird, bringt ihr Token mit. Ohne den Vorrang der Umgebung
 * würde die Staging-Kopie sich anschließend als Produktion melden.
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

    /**
     * Verrät, ob die Werte aus der Umgebung kommen — das Backend-Modul
     * muss dann erklären, warum es sie nicht ändern kann.
     */
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
