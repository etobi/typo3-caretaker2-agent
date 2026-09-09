<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * Das Ergebnis eines Providers — Daten und Zustand in einem.
 *
 * Der Zustand ist Pflicht, nicht Beiwerk: Ein Hub, der einen still
 * fehlgeschlagenen Provider nicht von einem leeren Ergebnis unterscheiden
 * kann, meldet im Fehlerfall Entwarnung. Deshalb gibt es kein "einfach
 * nichts zurückgeben".
 */
final class ProviderResult implements \JsonSerializable
{
    public const STATUS_OK = 'ok';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** @var string */
    private $status;

    /** @var array<string, mixed>|null */
    private $data;

    /** @var string|null Maschinenlesbarer Grund, z.B. composer_files_missing */
    private $reason;

    /** @var string|null Klartext für Menschen */
    private $message;

    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(string $status, ?array $data, ?string $reason, ?string $message)
    {
        $this->status = $status;
        $this->data = $data;
        $this->reason = $reason;
        $this->message = $message;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(self::STATUS_OK, $data, null, null);
    }

    /**
     * Teilweise geliefert. Was fehlt, steht in reason und message.
     *
     * @param array<string, mixed> $data
     */
    public static function degraded(array $data, string $reason, string $message): self
    {
        return new self(self::STATUS_DEGRADED, $data, $reason, $message);
    }

    /**
     * Nichts geliefert — und das ist eine Aussage, kein Schweigen.
     */
    public static function unavailable(string $reason, string $message): self
    {
        return new self(self::STATUS_UNAVAILABLE, null, $reason, $message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $out = ['status' => $this->status];
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        if ($this->message !== null) {
            $out['message'] = $this->message;
        }
        $out['data'] = $this->data;

        return $out;
    }
}
