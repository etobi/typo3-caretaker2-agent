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

final class ScheduleCommand extends Command
{
    /** @var SchedulerTaskInstaller */
    private $installer;

    public function __construct(SchedulerTaskInstaller $installer)
    {
        parent::__construct();
        $this->installer = $installer;
    }

    private function ensureBackendUser(): void
    {
        if (($GLOBALS['BE_USER']->user['admin'] ?? 0) === 1) {
            return;
        }

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
