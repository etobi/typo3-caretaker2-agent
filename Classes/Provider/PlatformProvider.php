<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * PHP und Datenbank, so wie diese Instanz sie wirklich vorfindet.
 *
 * Der Provider trägt mehr Gewicht, als sein Umfang vermuten lässt: Composer
 * entscheidet anhand der laufenden Umgebung, was installierbar ist. Ohne
 * diese Werte würde der Hub — der auf einer ganz anderen PHP-Version läuft —
 * Updates melden, die auf der Instanz gar nicht möglich sind. Der Hub baut
 * daraus seinen config.platform-Block.
 *
 * Gemeldet werden nur Rohwerte. Was davon ein Problem ist, entscheidet der
 * Hub, nicht der Agent.
 */
final class PlatformProvider implements ProviderInterface
{
    /** @var ConnectionPool */
    private $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getKey(): string
    {
        return 'platform';
    }

    public function collect(): ProviderResult
    {
        $data = [
            'php' => [
                'version' => PHP_VERSION,
                'versionId' => PHP_VERSION_ID,
                'family' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'architectureBits' => PHP_INT_SIZE * 8,
                'sapi' => PHP_SAPI,
                'extensions' => $this->collectExtensions(),
                'settings' => $this->collectSettings(),
            ],
        ];

        $database = $this->collectDatabase();

        if ($database === null) {
            // Teilweise geliefert — und der Hub erfährt genau das, statt
            // einen fehlenden Datenbankblock für "keine Datenbank" zu halten.
            return ProviderResult::degraded(
                $data,
                'database_version_unavailable',
                'PHP-Daten vollständig, die Datenbankversion war nicht ermittelbar.'
            );
        }

        $data['database'] = $database;

        return ProviderResult::ok($data);
    }

    /**
     * Alle geladenen Extensions mit Version, in der Schreibweise, die
     * Composer für config.platform erwartet.
     *
     * @return array<string, string>
     */
    private function collectExtensions(): array
    {
        $extensions = [];

        foreach (get_loaded_extensions() as $name) {
            // Composer's spelling: lowercase, spaces to hyphens. "Zend OPcache"
            // becomes ext-zend-opcache.
            $lower = str_replace(' ', '-', strtolower($name));
            if ($lower === 'core' || $lower === 'standard') {
                continue;
            }

            $version = phpversion($name);
            // Manche Extensions melden keine eigene Version. Composer nimmt
            // dann die PHP-Version an — dieselbe Annahme treffen wir hier.
            $extensions['ext-' . $lower] = is_string($version) && $version !== ''
                ? $version
                : PHP_VERSION;
        }

        ksort($extensions);

        return $extensions;
    }

    /**
     * @return array<string, string|int|bool>
     */
    private function collectSettings(): array
    {
        return [
            'memory_limit' => (string)ini_get('memory_limit'),
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'opcache_enabled' => (bool)ini_get('opcache.enable'),
            'disabled_functions' => (string)ini_get('disable_functions'),
        ];
    }

    /**
     * Doctrine hat die Versions-API zwischen den DBAL-Fassungen mehrfach
     * verschoben. Statt auf eine Variante zu setzen, gehen wir sie der Reihe
     * nach durch — der Agent muss von v11 bis v14 tragen.
     *
     * @return array<string, string>|null
     */
    private function collectDatabase(): ?array
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        } catch (\Throwable $e) {
            return null;
        }

        $platform = 'unknown';
        try {
            $platformClass = get_class($connection->getDatabasePlatform());
            $platform = $this->normalizePlatform($platformClass);
        } catch (\Throwable $e) {
            // Plattform unbekannt, Version vielleicht trotzdem ermittelbar.
        }

        $version = $this->detectServerVersion($connection);

        if ($version === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'serverVersion' => $this->stripVendorPrefix($version),
        ];
    }

    /**
     * @param mixed $connection
     */
    private function detectServerVersion($connection): ?string
    {
        // DBAL 3.3+
        if (method_exists($connection, 'getServerVersion')) {
            try {
                $version = $connection->getServerVersion();
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable $e) {
                // weiter unten
            }
        }

        // Ältere DBAL-Fassungen und alles andere: einfach fragen.
        foreach (['SELECT VERSION()', 'SHOW server_version'] as $sql) {
            try {
                $result = $connection->executeQuery($sql)->fetchOne();
                if (is_string($result) && $result !== '') {
                    return $result;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Some DBAL versions prefix the reported version with a vendor name, some
     * do not. The vendor is already in 'platform', so drop it either way.
     */
    private function stripVendorPrefix(string $version): string
    {
        return (string)preg_replace('/^(MySQL|MariaDB|PostgreSQL|SQLite)\s+/i', '', $version);
    }

    private function normalizePlatform(string $platformClass): string
    {
        $short = strtolower(substr((string)strrchr('\\' . $platformClass, '\\'), 1));

        foreach (['mariadb' => 'mariadb', 'mysql' => 'mysql', 'postgre' => 'postgresql', 'sqlite' => 'sqlite'] as $needle => $name) {
            if (strpos($short, $needle) !== false) {
                return $name;
            }
        }

        return $short;
    }
}
