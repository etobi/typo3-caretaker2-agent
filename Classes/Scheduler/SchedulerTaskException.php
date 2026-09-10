<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Scheduler;

/**
 * Why the scheduler task could not be set up. Carries the label the backend
 * module shows for it; the message itself is English, for the console and
 * the log.
 */
final class SchedulerTaskException extends \RuntimeException
{
    /** @var string */
    public $labelKey;

    /** @var list<string|int> */
    public $labelArguments;

    /**
     * @param list<string|int> $labelArguments
     */
    private function __construct(
        string $message,
        string $labelKey,
        array $labelArguments = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->labelKey = $labelKey;
        $this->labelArguments = $labelArguments;
    }

    public static function schedulerMissing(): self
    {
        return new self('The scheduler extension is not installed.', 'error.schedulerMissing');
    }

    public static function taskExists(): self
    {
        return new self('There already is a task for this command.', 'error.taskExists');
    }

    public static function removeFailed(\Throwable $cause): self
    {
        return new self(
            'The old task could not be removed: ' . $cause->getMessage(),
            'error.taskRemoveFailed',
            [$cause->getMessage()],
            $cause
        );
    }

    public static function saveFailed(\Throwable $cause): self
    {
        return new self(
            'The task could not be created: ' . $cause->getMessage(),
            'error.taskFailed',
            [$cause->getMessage()],
            $cause
        );
    }

    public static function noUser(): self
    {
        return new self('The task could not be created. Is a backend user missing?', 'error.taskNoUser');
    }
}
