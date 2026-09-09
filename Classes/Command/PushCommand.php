<?php

declare(strict_types=1);

namespace Caretaker2\Agent\Command;

use Caretaker2\Agent\Connection\HubClient;
use Caretaker2\Agent\Connection\HubConnectionException;
use Caretaker2\Agent\Inventory\InventoryBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Der einzige Einstiegspunkt für den Push.
 *
 * Drei Auslöser, eine Implementierung: nach dem Deployment von Hand, täglich
 * über den eingebauten Scheduler-Task für Konsolenbefehle, und auf Zuruf des
 * Hubs. Ein Console-Command ist außerdem die stabilste Schnittstelle, die
 * TYPO3 über v11 bis v14 anbietet — eigene Scheduler-Task-Klassen haben sich
 * in dieser Spanne mehrfach geändert.
 */
final class PushCommand extends Command
{
    /** @var InventoryBuilder */
    private $inventoryBuilder;

    /** @var HubClient */
    private $hubClient;

    public function __construct(InventoryBuilder $inventoryBuilder, HubClient $hubClient)
    {
        parent::__construct();
        $this->inventoryBuilder = $inventoryBuilder;
        $this->hubClient = $hubClient;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Sammelt das Inventar dieser Instanz und meldet es an den Hub')
            ->addOption(
                'print',
                'p',
                InputOption::VALUE_NONE,
                'Inventar ausgeben statt senden — zeigt genau, was diese Instanz verlassen würde'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inventory = $this->inventoryBuilder->build();

        if ($input->getOption('print')) {
            $output->writeln((string)json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        try {
            $this->hubClient->pushInventory($inventory);
        } catch (HubConnectionException $e) {
            // Fehlercode, damit eine Deployment-Pipeline es merkt. Wem das
            // egal ist, hängt "|| true" an.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Inventar gemeldet (%d Provider, Schema v%d).',
            count($inventory['providers']),
            $inventory['schemaVersion']
        ));

        foreach ($inventory['providers'] as $key => $result) {
            $status = $result->getStatus();
            if ($status !== 'ok') {
                $io->warning(sprintf('Provider "%s": %s', $key, $status));
            }
        }

        return Command::SUCCESS;
    }
}
