<?php

declare(strict_types=1);

namespace Everysize\Checkout\Command;

use Everysize\Checkout\Service\CronjobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportOrders extends Command
{
    private CronjobService $cronjobService;

    public function __construct(CronjobService $cronjobService, ?string $name = null)
    {
        parent::__construct($name);
        $this->cronjobService = $cronjobService;
    }

    public function configure(): void
    {
        $this->setName('everysize:order:import');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->cronjobService->importOrdersFromEverysize();

        foreach ($results as $result) {
            $output->writeln($result);
        }

        return Command::SUCCESS;
    }
}
