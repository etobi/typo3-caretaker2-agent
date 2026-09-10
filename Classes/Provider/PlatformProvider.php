<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * PHP and the database as this instance actually finds them.
 */
final class PlatformProvider implements ProviderInterface
{
    /**
     * What differs between a CLI and an FPM run of the same installation:
     * the SAPI itself, the ini settings it comes with, and the extensions
     * only one of them loads.
     */
    private const VOLATILE = [
        'php.sapi',
        'php.settings',
        'php.extensions.ext-pcntl',
        'php.extensions.ext-posix',
        'php.extensions.ext-readline',
        'php.extensions.ext-cgi-fcgi',
        'php.extensions.ext-apache2handler',
        'php.extensions.ext-litespeed',
    ];

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
            // Partly delivered, and the hub is told exactly that instead of
            // reading a missing database block as "no database".
            return ProviderResult::degraded(
                $data,
                'database_version_unavailable',
                'The PHP data is complete, the database version could not be determined.',
                self::VOLATILE
            );
        }

        $data['database'] = $database;

        return ProviderResult::ok($data, self::VOLATILE);
    }

    /**
     * Every loaded extension with its version, spelled the way composer
     * expects it in config.platform.
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
            // Some extensions report no version of their own. Composer then
            // assumes the PHP version, and so do we.
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
     * Doctrine moved the version API around between DBAL releases.
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
            // Platform unknown, the version may still be obtainable.
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
            }
        }

        // Older DBAL releases and everything else: just ask.
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
