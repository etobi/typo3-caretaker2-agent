<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Command;

use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\TokenStorage;
use Caretaker2\Agent\Http\InstanceOrigin;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConnectCommand extends Command
{
    /** @var HubClient */
    private $hubClient;

    /** @var TokenStorage */
    private $tokenStorage;

    /** @var InstanceOrigin */
    private $instanceOrigin;

    public function __construct(HubClient $hubClient, TokenStorage $tokenStorage, InstanceOrigin $instanceOrigin)
    {
        parent::__construct();
        $this->hubClient = $hubClient;
        $this->tokenStorage = $tokenStorage;
        $this->instanceOrigin = $instanceOrigin;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Connects this instance to a hub')
            ->addArgument('hub-url', InputArgument::REQUIRED, 'Base URL of the hub')
            ->addArgument('code', InputArgument::REQUIRED, 'Enrollment code from the hub')
            ->addArgument('instance-url', InputArgument::OPTIONAL, 'URL of this instance')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Basic Auth user, for a hub behind HTTP Basic Auth')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Basic Auth password; CARETAKER2_HUB_PASSWORD keeps it out of the shell history');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->tokenStorage->isManagedByEnvironment()) {
            $io->error('The token comes from the environment (CARETAKER2_TOKEN) and is not overwritten here.');

            return Command::FAILURE;
        }

        $instanceUrl = (string)($input->getArgument('instance-url') ?: $this->guessInstanceUrl());

        try {
            $this->hubClient->enroll(
                (string)$input->getArgument('hub-url'),
                (string)$input->getArgument('code'),
                $instanceUrl,
                (string)$input->getOption('user'),
                (string)$input->getOption('password')
            );
        } catch (HubConnectionException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Connected. Run "caretaker2:push" now, or create the scheduler task.');

        return Command::SUCCESS;
    }

    private function guessInstanceUrl(): string
    {
        return $this->instanceOrigin->find() ?? 'https://' . (string)(getenv('HOSTNAME') ?: 'unknown');
    }
}
