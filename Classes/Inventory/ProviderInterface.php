<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Inventory;

/**
 * Ein Provider sammelt einen Ausschnitt des Inventars.
 *
 * Provider bewerten nichts. Sie entscheiden nicht, ob ein Wert gut oder
 * schlecht ist, und sie kennen keine Schwellwerte — das ist alles Sache
 * des Hubs. Ein Provider liest, und wenn er nicht lesen kann, sagt er das.
 *
 * Eigene Provider werden über den Service-Tag "caretaker2.provider"
 * registriert und landen unter ihrem Schlüssel im Inventar. Der Hub muss
 * sie nicht kennen, um sie zu speichern und anzuzeigen.
 */
interface ProviderInterface
{
    /**
     * Schlüssel im Inventar, z.B. "core". Kleinbuchstaben, keine Punkte.
     */
    public function getKey(): string;

    /**
     * Darf nicht werfen. Jeder Fehlerfall ist ein ProviderResult mit
     * Status degraded oder unavailable — nur so erfährt der Hub davon.
     */
    public function collect(): ProviderResult;
}
