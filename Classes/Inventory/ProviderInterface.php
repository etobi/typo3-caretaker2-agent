<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * A provider collects one part of the inventory.
 */
interface ProviderInterface
{
    /**
     * The key in the inventory, "core" for instance. Lower case, no dots.
     */
    public function getKey(): string;

    /**
     * Must not throw. Every failure is a ProviderResult with status degraded
     * or unavailable — that is the only way the hub gets to hear about it.
     */
    public function collect(): ProviderResult;
}
