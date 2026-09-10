<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The tables as the database server sees them: character sets, engines,
 * sizes. A latin1 table in a v13 installation is a migration problem that
 * nobody notices until the next major, and sys_log grows until somebody
 * looks.
 *
 * Read from information_schema, which MySQL and MariaDB share. Other
 * database servers are named as not covered rather than left out quietly.
 */
final class DatabaseProvider implements ProviderInterface
{
    /**
     * Row counts and sizes grow with every request. They are worth showing,
     * but not a change to the installation.
     */
    private const VOLATILE = ['sizes'];

    /** @var ConnectionPool */
    private $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getKey(): string
    {
        return 'database';
    }

    public function collect(): ProviderResult
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            $platform = $this->platformOf($connection);
        } catch (\Throwable $e) {
            return ProviderResult::unavailable(
                'connection_failed',
                'The database connection could not be opened: ' . $e->getMessage()
            );
        }

        if ($platform !== 'mysql' && $platform !== 'mariadb') {
            return ProviderResult::unavailable(
                'platform_unsupported',
                sprintf('Only MySQL and MariaDB are read so far; this instance runs on %s.', $platform)
            );
        }

        try {
            $tables = $this->fetchTables($connection);
            $columnCharsets = $this->fetchColumnCharsets($connection);
        } catch (\Throwable $e) {
            return ProviderResult::unavailable(
                'schema_unreadable',
                'The table list could not be read from information_schema: ' . $e->getMessage()
            );
        }

        $data = [
            'platform' => $platform,
            'characterSet' => null,
            'collation' => null,
            'tableCount' => count($tables),
            'tables' => [],
            'sizes' => [
                'totalBytes' => 0,
                'dataBytes' => 0,
                'indexBytes' => 0,
                'tables' => [],
            ],
        ];

        foreach ($tables as $row) {
            $name = (string)($row['TABLE_NAME'] ?? '');
            $collation = (string)($row['TABLE_COLLATION'] ?? '');
            $dataBytes = (int)($row['DATA_LENGTH'] ?? 0);
            $indexBytes = (int)($row['INDEX_LENGTH'] ?? 0);

            $data['tables'][] = [
                'name' => $name,
                'engine' => (string)($row['ENGINE'] ?? ''),
                'characterSet' => $this->characterSetOf($collation),
                'collation' => $collation,
                'columnCharacterSets' => $columnCharsets[$name] ?? [],
            ];

            $data['sizes']['tables'][$name] = [
                'rows' => (int)($row['TABLE_ROWS'] ?? 0),
                'dataBytes' => $dataBytes,
                'indexBytes' => $indexBytes,
            ];
            $data['sizes']['dataBytes'] += $dataBytes;
            $data['sizes']['indexBytes'] += $indexBytes;
        }

        $data['sizes']['totalBytes'] = $data['sizes']['dataBytes'] + $data['sizes']['indexBytes'];

        try {
            $default = $this->fetchDefaults($connection);
            $data['characterSet'] = $this->normalizeCharacterSet((string)($default['DEFAULT_CHARACTER_SET_NAME'] ?? ''));
            $data['collation'] = (string)($default['DEFAULT_COLLATION_NAME'] ?? '');
        } catch (\Throwable $e) {
            return ProviderResult::degraded(
                $data,
                'defaults_unreadable',
                'The tables are listed, but the database\'s default character set could not be read: ' . $e->getMessage(),
                self::VOLATILE
            );
        }

        return ProviderResult::ok($data, self::VOLATILE);
    }

    /**
     * @param mixed $connection
     * @return list<array<string, mixed>>
     */
    private function fetchTables($connection): array
    {
        return $connection->executeQuery(
            'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION'
            . ' FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\''
            . ' ORDER BY TABLE_NAME'
        )->fetchAllAssociative();
    }

    /**
     * Per table, how many text columns use which character set. A table can
     * be utf8mb4 by default and still carry a latin1 column from an old
     * migration.
     *
     * @param mixed $connection
     * @return array<string, array<string, int>>
     */
    private function fetchColumnCharsets($connection): array
    {
        $rows = $connection->executeQuery(
            'SELECT TABLE_NAME, CHARACTER_SET_NAME, COUNT(*) AS COLUMN_COUNT'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND CHARACTER_SET_NAME IS NOT NULL'
            . ' GROUP BY TABLE_NAME, CHARACTER_SET_NAME'
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $table = (string)($row['TABLE_NAME'] ?? '');
            $charset = $this->normalizeCharacterSet((string)($row['CHARACTER_SET_NAME'] ?? ''));
            if ($table === '' || $charset === '') {
                continue;
            }
            $out[$table][$charset] = (int)($row['COLUMN_COUNT'] ?? 0);
        }

        return $out;
    }

    /**
     * @param mixed $connection
     * @return array<string, mixed>
     */
    private function fetchDefaults($connection): array
    {
        $row = $connection->executeQuery(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME'
            . ' FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * The character set is the part of the collation before the underscore:
     * utf8mb4_unicode_ci is utf8mb4.
     */
    private function characterSetOf(string $collation): string
    {
        if ($collation === '') {
            return '';
        }

        $position = strpos($collation, '_');

        return $this->normalizeCharacterSet($position === false ? $collation : substr($collation, 0, $position));
    }

    /**
     * MariaDB still calls the three-byte UTF-8 "utf8", MySQL 8 says utf8mb3.
     * One name for the same thing, so the hub does not need to know both.
     */
    private function normalizeCharacterSet(string $charset): string
    {
        $charset = strtolower(trim($charset));

        return $charset === 'utf8' ? 'utf8mb3' : $charset;
    }

    /**
     * @param mixed $connection
     */
    private function platformOf($connection): string
    {
        $short = strtolower(substr((string)strrchr('\\' . get_class($connection->getDatabasePlatform()), '\\'), 1));

        foreach (['mariadb' => 'mariadb', 'mysql' => 'mysql', 'postgre' => 'postgresql', 'sqlite' => 'sqlite'] as $needle => $name) {
            if (strpos($short, $needle) !== false) {
                return $name;
            }
        }

        return $short;
    }
}
