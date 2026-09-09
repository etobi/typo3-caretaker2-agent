<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * A provider collects one part of the inventory.
 *
 * Providers judge nothing. They do not decide whether a value is good or bad
 * and they know no thresholds — that is all the hub's business. A provider
 * reads, and when it cannot read, it says so.
 *
 * Providers of your own are registered through the service tag
 * "caretaker2.provider" and land in the inventory under their key. The hub
 * does not have to know them to store and show them.
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
