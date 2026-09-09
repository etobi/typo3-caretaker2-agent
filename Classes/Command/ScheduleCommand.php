<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Command;

use Caretaker2\Agent\Scheduler\SchedulerTaskException;
use Caretaker2\Agent\Scheduler\SchedulerTaskInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Same as the button in the backend module, for provisioning.
 */
final class ScheduleCommand extends Command
{
    /** @var SchedulerTaskInstaller */
    private $installer;

    public function __construct(SchedulerTaskInstaller $installer)
    {
        parent::__construct();
        $this->installer = $installer;
    }

    /**
     * From v14 the scheduler writes through DataHandler, which refuses to touch
     * the table without an authenticated administrator. The module has one; on
     * the console the command line user has to be logged in explicitly —
     * initialising it is not enough, and the core does the same in
     * SchedulerTaskRepository::updateExecution().
     */
    private function ensureBackendUser(): void
    {
        if (($GLOBALS['BE_USER']->user['admin'] ?? 0) === 1) {
            return;
        }

        // Der Rückgabewert ist erst ab v13 der Benutzer; davor liefert die
        // Methode nichts und setzt nur $GLOBALS.
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);

        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof CommandLineUserAuthentication) {
            $user->authenticate();
        }
    }

    protected function configure(): void
    {
        $this->setDescription('Creates the daily scheduler task for the push');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->ensureBackendUser();

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
