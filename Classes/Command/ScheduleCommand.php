<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Command;

use Caretaker2\Agent\Scheduler\SchedulerTaskException;
use Caretaker2\Agent\Scheduler\SchedulerTaskInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

final class ScheduleCommand extends Command
{
    /** @var SchedulerTaskInstaller */
    private $installer;

    public function __construct(SchedulerTaskInstaller $installer)
    {
        parent::__construct();
        $this->installer = $installer;
    }

    protected function configure(): void
    {
        $this->setDescription('Creates the daily scheduler task for the push');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The console bootstrap creates the _cli_ user but does not log it
        // in. Saving a scheduler task goes through the DataHandler, and that
        // wants a logged-in admin. The core's own commands do the same.
        Bootstrap::initializeBackendAuthentication();

        try {
            $this->installer->install();
        } catch (SchedulerTaskException $e) {
            $io->warning($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Daily scheduler task created.');

        return Command::SUCCESS;
    }
}
