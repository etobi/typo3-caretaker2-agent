<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * Assembles the inventory from every registered provider.
 */
final class InventoryBuilder
{
    /**
     * Only rises on breaking changes to the format. The hub understands n-2.
     */
    public const SCHEMA_VERSION = 1;

    public const AGENT_VERSION = '0.1.0';

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

            // A provider is not supposed to throw. If one does, it must not cost
            // the whole inventory — that one part is missing, and the hub is
            // told why.
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
            'agent' => ['version' => self::AGENT_VERSION],
            'providers' => $providers,
        ];
    }

    /**
     * A fingerprint over the content, without generatedAt — otherwise every
     * push would be a change. The hub only stores what actually differs.
     */
    public function fingerprint(array $inventory): string
    {
        $relevant = $inventory;
        unset($relevant['generatedAt']);

        return hash('sha256', (string)json_encode($relevant));
    }
}
