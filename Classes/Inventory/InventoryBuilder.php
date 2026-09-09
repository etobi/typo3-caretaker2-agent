<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * Setzt aus allen registrierten Providern das Inventar zusammen.
 */
final class InventoryBuilder
{
    /**
     * Steigt nur bei brechenden Änderungen am Format. Der Hub versteht n-2.
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

            // Ein Provider soll nicht werfen. Tut er es doch, darf das nicht
            // das ganze Inventar kosten — dann fehlt eben dieser eine Teil,
            // und der Hub erfährt warum.
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
     * Fingerabdruck über den Inhalt — ohne generatedAt, sonst wäre jeder
     * Push eine Änderung. Der Hub speichert nur, was sich wirklich
     * unterscheidet.
     */
    public function fingerprint(array $inventory): string
    {
        $relevant = $inventory;
        unset($relevant['generatedAt']);

        return hash('sha256', (string)json_encode($relevant));
    }
}
