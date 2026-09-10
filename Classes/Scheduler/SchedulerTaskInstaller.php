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
        return $this->findUid() !== 0;
    }

    /**
     * Whether the existing task actually repeats.
     *
     * "A task exists" is not the same as "this instance reports daily". A task
     * created on v14 before the cron fix carries an interval the DataHandler
     * dropped: it ran once and then never again, while the module cheerfully
     * reported everything was in place. That is the failure this whole product
     * exists to catch, so it must not happen in its own setup.
     *
     * Returns true when we cannot tell. Crying wolf over a task that is
     * probably fine would be worse than staying quiet.
     */
    public function isRecurring(): bool
    {
        $uid = $this->findUid();
        if ($uid === 0) {
            return false;
        }

        try {
            $task = $this->fetchTask($uid);
            $execution = $task === null ? null : $task->getExecution();
        } catch (\Throwable $e) {
            return true;
        }

        if ($execution === null || !method_exists($execution, 'getInterval')) {
            return true;
        }

        return (int)$execution->getInterval() > 0 || (string)$execution->getCronCmd() !== '';
    }

    /**
     * @throws SchedulerTaskException
     */
    public function install(): void
    {
        if (!$this->isAvailable()) {
            throw SchedulerTaskException::schedulerMissing();
        }

        if ($this->exists()) {
            throw SchedulerTaskException::taskExists();
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
     * Removes the task and creates it again. The only repair that works across
     * all four versions — editing an execution in place needs a different API
     * in each of them.
     *
     * @throws SchedulerTaskException
     */
    public function repair(): void
    {
        $uid = $this->findUid();
        if ($uid !== 0) {
            $this->remove($uid);
        }

        $this->install();
    }

    private function findUid(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $column = $this->usesTaskTypeApi() ? 'tasktype' : 'serialized_task_object';

        return (int)$qb
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->like($column, $qb->createNamedParameter('%' . self::COMMAND . '%'))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return object|null
     */
    private function fetchTask(int $uid)
    {
        return $this->usesRepository()
            ? $this->repository()->findByUid($uid)
            : GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Scheduler::class)->fetchTask($uid);
    }

    /**
     * @throws SchedulerTaskException
     */
    private function remove(int $uid): void
    {
        try {
            $task = $this->fetchTask($uid);

            if ($task === null) {
                return;
            }

            if ($this->usesRepository()) {
                $this->repository()->remove($task);
            } else {
                $task->remove();
            }
        } catch (\Throwable $e) {
            throw SchedulerTaskException::removeFailed($e);
        }
    }

    /**
     * @param object $task
     * @throws SchedulerTaskException
     */
    private function persist($task): void
    {
        try {
            $saved = $this->usesRepository()
                ? $this->repository()->add($task)
                : GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Scheduler::class)->addTask($task);
        } catch (\Throwable $e) {
            throw SchedulerTaskException::saveFailed($e);
        }

        if ($saved === false) {
            throw SchedulerTaskException::noUser();
        }
    }

    /**
     * v12 moved loading and saving tasks from the Scheduler service into a
     * repository.
     */
    private function usesRepository(): bool
    {
        return class_exists(\TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository::class);
    }

    /**
     * @return object the SchedulerTaskRepository, on versions that have one
     */
    private function repository()
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository::class);
    }

    /**
     * v14 stores the command as the task type instead of inside a serialised
     * task object.
     */
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
}
