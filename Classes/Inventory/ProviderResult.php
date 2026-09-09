<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * What a provider found: data and state in one.
 *
 * The state is required, not decoration. A hub that cannot tell a silently
 * failed provider from an empty result gives an all-clear precisely when
 * something went wrong. Which is why "just return nothing" is not offered.
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
