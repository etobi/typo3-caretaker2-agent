<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Provider;

use Caretaker2\Agent\Inventory\ProviderInterface;
use Caretaker2\Agent\Inventory\ProviderResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Whether maintenance runs at all: when the scheduler last ran, which tasks
 * exist, and which of them failed.
 *
 * The task table is read directly rather than through the scheduler's own
 * classes. Those changed with every major version, and loading a task means
 * unserialising it, which needs the task's class and runs its code. A row
 * does neither.
 */
final class SchedulerProvider implements ProviderInterface
{
    private const TABLE = 'tx_scheduler_task';
    private const MAX_FAILURE_LENGTH = 500;

    /**
     * Timestamps of the last and next run move with every cron tick. They
     * are worth showing, but not a change to the installation.
     */
    private const VOLATILE = ['runs'];

    /** @var ConnectionPool */
    private $connectionPool;

    /** @var Registry */
    private $registry;

    public function __construct(ConnectionPool $connectionPool, Registry $registry)
    {
        $this->connectionPool = $connectionPool;
        $this->registry = $registry;
    }

    public function getKey(): string
    {
        return 'scheduler';
    }

    public function collect(): ProviderResult
    {
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return ProviderResult::unavailable(
                'scheduler_not_installed',
                'The scheduler extension is not installed, so nothing runs on a schedule here.'
            );
        }

        try {
            $rows = $this->fetchRows();
        } catch (\Throwable $e) {
            return ProviderResult::unavailable(
                'tasks_unreadable',
                'The scheduler tasks could not be read: ' . $e->getMessage()
            );
        }

        $tasks = [];
        $runs = [];
        $enabled = 0;

        foreach ($rows as $row) {
            $uid = (int)($row['uid'] ?? 0);
            $disabled = (int)($row['disable'] ?? 0) === 1;
            if (!$disabled) {
                $enabled++;
            }

            $tasks[] = [
                'uid' => $uid,
                'type' => $this->typeOf($row),
                'description' => trim((string)($row['description'] ?? '')),
                'disabled' => $disabled,
                'group' => (int)($row['task_group'] ?? 0),
                'lastFailure' => $this->failureOf($row['lastexecution_failure'] ?? null),
                'lastContext' => (string)($row['lastexecution_context'] ?? ''),
            ];

            $runs[(string)$uid] = [
                'next' => (int)($row['nextexecution'] ?? 0),
                'last' => (int)($row['lastexecution_time'] ?? 0),
            ];
        }

        $data = [
            'taskCount' => count($tasks),
            'enabledCount' => $enabled,
            'tasks' => $tasks,
            'runs' => [
                'lastRun' => null,
                'tasks' => $runs,
            ],
        ];

        try {
            $data['runs']['lastRun'] = $this->lastRun();
        } catch (\Throwable $e) {
            return ProviderResult::degraded(
                $data,
                'last_run_unreadable',
                'The tasks are listed, but when the scheduler last ran could not be read: ' . $e->getMessage(),
                self::VOLATILE
            );
        }

        return ProviderResult::ok($data, self::VOLATILE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * The scheduler notes every run in the registry, whichever way it was
     * started. The scheduler's own module shows this same value.
     *
     * @return array{start: int, end: int, type: string}|null
     */
    private function lastRun(): ?array
    {
        $lastRun = $this->registry->get('tx_scheduler', 'lastRun');
        if (!is_array($lastRun) || !isset($lastRun['end'])) {
            return null;
        }

        return [
            'start' => (int)($lastRun['start'] ?? 0),
            'end' => (int)$lastRun['end'],
            'type' => (string)($lastRun['type'] ?? ''),
        ];
    }

    /**
     * What a task is: the command it runs, or else its class. v14 keeps that
     * in a column of its own; before that it sits inside the serialised task
     * object, which is read as text and never unserialised.
     *
     * @param array<string, mixed> $row
     */
    private function typeOf(array $row): string
    {
        $taskType = trim((string)($row['tasktype'] ?? ''));
        if ($taskType !== '') {
            return $taskType;
        }

        $serialized = (string)($row['serialized_task_object'] ?? '');

        if (preg_match('/commandIdentifier";s:\d+:"([^"]*)"/', $serialized, $match) === 1 && $match[1] !== '') {
            return $match[1];
        }

        if (preg_match('/^O:\d+:"([^"]+)"/', $serialized, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    /**
     * The last failure as text. Older versions store a serialised array of
     * code and message, and the message alone says what went wrong.
     *
     * @param mixed $value
     */
    private function failureOf($value): string
    {
        $failure = trim((string)$value);
        if ($failure === '') {
            return '';
        }

        if (strpos($failure, 'a:') === 0) {
            $decoded = @unserialize($failure, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $failure = trim((string)($decoded['message'] ?? ''));
                if ($failure === '' && isset($decoded['code'])) {
                    $failure = 'Error code ' . (string)$decoded['code'];
                }
            }
        } elseif (strpos($failure, '{') === 0) {
            $decoded = json_decode($failure, true);
            if (is_array($decoded) && isset($decoded['message'])) {
                $failure = trim((string)$decoded['message']);
            }
        }

        return mb_strlen($failure) > self::MAX_FAILURE_LENGTH
            ? mb_substr($failure, 0, self::MAX_FAILURE_LENGTH) . '…'
            : $failure;
    }
}
