<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Command;

use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Connection\TokenStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dasselbe wie der Knopf im Backend-Modul, nur für Deployment und
 * automatisiertes Provisioning.
 */
final class ConnectCommand extends Command
{
    /** @var HubClient */
    private $hubClient;

    /** @var TokenStorage */
    private $tokenStorage;

    public function __construct(HubClient $hubClient, TokenStorage $tokenStorage)
    {
        parent::__construct();
        $this->hubClient = $hubClient;
        $this->tokenStorage = $tokenStorage;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Verbindet diese Instanz mit einem Hub')
            ->addArgument('hub-url', InputArgument::REQUIRED, 'Basis-URL des Hubs')
            ->addArgument('code', InputArgument::REQUIRED, 'Enrollment-Code aus dem Hub')
            ->addArgument('instance-url', InputArgument::OPTIONAL, 'URL dieser Instanz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->tokenStorage->isManagedByEnvironment()) {
            $io->error('Token kommt aus der Umgebung (CARETAKER2_TOKEN) und wird hier nicht überschrieben.');

            return Command::FAILURE;
        }

        $instanceUrl = (string)($input->getArgument('instance-url') ?: $this->guessInstanceUrl());

        try {
            $this->hubClient->enroll(
                (string)$input->getArgument('hub-url'),
                (string)$input->getArgument('code'),
                $instanceUrl
            );
        } catch (HubConnectionException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Verbunden. Jetzt "caretaker2:push" ausführen oder den Scheduler-Task anlegen.');

        return Command::SUCCESS;
    }

    private function guessInstanceUrl(): string
    {
        $host = getenv('TYPO3_BASE_URL');
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return 'https://' . (string)(getenv('HOSTNAME') ?: 'unbekannt');
    }
}
