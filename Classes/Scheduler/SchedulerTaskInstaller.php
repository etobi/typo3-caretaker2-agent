<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Scheduler;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the daily scheduler task
 */
final class SchedulerTaskInstaller
{
    private const LL = 'LLL:EXT:caretaker2_agent/Resources/Private/Language/locallang.xlf:';

    public const COMMAND = 'caretaker2:push';

    private const TABLE = 'tx_scheduler_task';
    private const INTERVAL_SECONDS = 86400;

    /** @var ConnectionPool */
    private $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('scheduler')
            && class_exists(\TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask::class);
    }

    public function exists(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $column = $this->usesTaskTypeApi() ? 'tasktype' : 'serialized_task_object';

        return (int)$qb
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->like($column, $qb->createNamedParameter('%' . self::COMMAND . '%'))
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @throws SchedulerTaskException
     */
    public function install(): void
    {
        if (!$this->isAvailable()) {
            throw new SchedulerTaskException($this->ll('error.schedulerMissing'));
        }

        if ($this->exists()) {
            throw new SchedulerTaskException($this->ll('error.taskExists'));
        }

        $task = GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask::class);
        $task->setDescription('Reports the inventory of this instance to the Caretaker2 hub.');

        if ($this->usesTaskTypeApi()) {
            $task->setTaskType(self::COMMAND);
            $task->setTaskParameters(['commandIdentifier' => self::COMMAND]);
        } else {
            $task->setCommandIdentifier(self::COMMAND);
        }

        $start = $this->nextNightlyStart();

        if (method_exists($task, 'registerRecurringExecution')) {
            $task->registerRecurringExecution($start, self::INTERVAL_SECONDS);
        } else {
            $task->setExecution(
                \TYPO3\CMS\Scheduler\Execution::createRecurringExecution(
                    $start,
                    0,
                    0,
                    false,
                    sprintf('%d 3 * * *', (int)date('i', $start))
                )
            );
        }

        $this->persist($task);
    }

    /**
     * @param object $task
     * @throws SchedulerTaskException
     */
    private function persist($task): void
    {
        try {
            $saved = class_exists(\TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository::class)
                ? GeneralUtility::makeInstance(
                    \TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository::class
                )->add($task)
                : GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Scheduler::class)->addTask($task);
        } catch (\Throwable $e) {
            throw new SchedulerTaskException($this->ll('error.taskFailed', $e->getMessage()), 0, $e);
        }

        if ($saved === false) {
            throw new SchedulerTaskException(
                $this->ll('error.taskNoUser')
            );
        }
    }

    private function usesTaskTypeApi(): bool
    {
        return method_exists(\TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask::class, 'setTaskType');
    }

    private function nextNightlyStart(): int
    {
        $minute = abs(crc32((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? gethostname()))) % 60;
        $start = mktime(3, $minute, 0) ?: time();

        return $start < time() ? $start + self::INTERVAL_SECONDS : $start;
    }

    /**
     * @param string|int ...$args
     */
    private function ll(string $key, ...$args): string
    {
        $text = $GLOBALS['LANG']->sL(self::LL . $key);

        return $args === [] ? $text : vsprintf($text, $args);
    }
}
