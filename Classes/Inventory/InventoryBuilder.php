<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

use Caretaker2\Agent\AgentVersion;

final class InventoryBuilder
{
    /**
     * Only rises on breaking changes to the format.
     */
    public const SCHEMA_VERSION = 1;

    /** @var iterable<ProviderInterface> */
    private $providers;

    /**
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            $key = $provider->getKey();

            try {
                $providers[$key] = $provider->collect();
            } catch (\Throwable $e) {
                $providers[$key] = ProviderResult::unavailable(
                    'provider_threw',
                    sprintf('%s: %s', get_class($e), $e->getMessage())
                );
            }
        }

        ksort($providers);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM),
            'agent' => ['version' => AgentVersion::current()],
            'providers' => $providers,
        ];
    }
}
