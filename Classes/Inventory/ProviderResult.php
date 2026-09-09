<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * What a provider found: data and state in one.
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

    /** @var string|null */
    private $reason;

    /** @var string|null */
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
     * Partly delivered. What is missing is in reason and message.
     *
     * @param array<string, mixed> $data
     */
    public static function degraded(array $data, string $reason, string $message): self
    {
        return new self(self::STATUS_DEGRADED, $data, $reason, $message);
    }

    /**
     * Nothing delivered — which is a statement, not silence.
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
