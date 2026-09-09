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
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The same as the button in the backend module, for deployments and
 * automated provisioning.
 */
final class ConnectCommand extends Command
{
    /** @var HubClient */
    private $hubClient;

    /** @var TokenStorage */
    private $tokenStorage;

    /** @var SiteFinder */
    private $siteFinder;

    public function __construct(HubClient $hubClient, TokenStorage $tokenStorage, SiteFinder $siteFinder)
    {
        parent::__construct();
        $this->hubClient = $hubClient;
        $this->tokenStorage = $tokenStorage;
        $this->siteFinder = $siteFinder;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Connects this instance to a hub')
            ->addArgument('hub-url', InputArgument::REQUIRED, 'Base URL of the hub')
            ->addArgument('code', InputArgument::REQUIRED, 'Enrollment code from the hub')
            ->addArgument('instance-url', InputArgument::OPTIONAL, 'URL of this instance');
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
                $instanceUrl
            );
        } catch (HubConnectionException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Connected. Run "caretaker2:push" now, or create the scheduler task.');

        return Command::SUCCESS;
    }

    /**
     * The address the hub will knock on later.
     *
     * On the console there is no request to take it from, and the machine's
     * host name is not it: in a container that is the container's name, which
     * resolves nowhere outside. The site configuration is the one place in the
     * installation that states how the site is actually reached, so it goes
     * first — the environment variable stays ahead of it for the case where an
     * instance sits behind something the site configuration does not know
     * about.
     */
    private function guessInstanceUrl(): string
    {
        $configured = getenv('TYPO3_BASE_URL');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = $site->getBase();
            $host = $base->getHost();

            if ($host === '') {
                continue;
            }

            $url = ($base->getScheme() ?: 'https') . '://' . $host;
            $port = $base->getPort();
            if ($port !== null && !in_array($port, [80, 443], true)) {
                $url .= ':' . $port;
            }

            return $url;
        }

        return 'https://' . (string)(getenv('HOSTNAME') ?: 'unknown');
    }
}
