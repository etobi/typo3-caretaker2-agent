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

    /**
     * Left out of the first push after connecting. TYPO3's own checks call
     * the instance over HTTP a dozen times and wait up to ten seconds for
     * each answer, so a fresh connection would hang on them for a minute.
     */
    private const SLOW_PROVIDERS = ['reports'];

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
        return $this->collect([]);
    }

    /**
     * Enough for the hub to show the instance right away: everything that
     * is done in a moment. The rest follows with the first scheduled push
     * or the next click on "Send data now".
     *
     * @return array<string, mixed>
     */
    public function buildFirst(): array
    {
        return $this->collect(self::SLOW_PROVIDERS);
    }

    /**
     * @param list<string> $skippedKeys
     * @return array<string, mixed>
     */
    private function collect(array $skippedKeys): array
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            $key = $provider->getKey();
            if (in_array($key, $skippedKeys, true)) {
                continue;
            }

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
