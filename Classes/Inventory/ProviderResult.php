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

    /** @var list<string> */
    private $volatile;

    /**
     * @param array<string, mixed>|null $data
     * @param list<string> $volatile
     */
    private function __construct(string $status, ?array $data, ?string $reason, ?string $message, array $volatile)
    {
        $this->status = $status;
        $this->data = $data;
        $this->reason = $reason;
        $this->message = $message;
        $this->volatile = $volatile;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $volatile see volatile()
     */
    public static function ok(array $data, array $volatile = []): self
    {
        return new self(self::STATUS_OK, $data, null, null, $volatile);
    }

    /**
     * Partly delivered. What is missing is in reason and message.
     *
     * @param array<string, mixed> $data
     * @param list<string> $volatile see volatile()
     */
    public static function degraded(array $data, string $reason, string $message, array $volatile = []): self
    {
        return new self(self::STATUS_DEGRADED, $data, $reason, $message, $volatile);
    }

    /**
     * Nothing delivered — which is a statement, not silence.
     */
    public static function unavailable(string $reason, string $message): self
    {
        return new self(self::STATUS_UNAVAILABLE, null, $reason, $message, []);
    }

    /**
     * Paths under data, dotted, that depend on the runtime that collected
     * them rather than on the instance: a scheduler push runs under CLI, a
     * hub-triggered push under FPM. The hub still stores and shows these
     * values, but leaves them out when deciding whether anything changed.
     * A single "*" means everything this provider delivers.
     *
     * @return list<string>
     */
    public function volatile(): array
    {
        return $this->volatile;
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
        if ($this->volatile !== []) {
            $out['volatile'] = $this->volatile;
        }
        $out['data'] = $this->data;

        return $out;
    }
}
