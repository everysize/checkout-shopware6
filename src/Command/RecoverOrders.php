<?php

declare(strict_types=1);

namespace Everysize\Checkout\Command;

use Everysize\Checkout\Dbal\DataProvider;
use Everysize\Checkout\Service\ConfigProvider;
use Everysize\Checkout\Service\MappingRecoveryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds order assignments from the checkout (MappingRecoveryService), for
 * every access data configured in the shop. With --dry-run nothing is written.
 */
class RecoverOrders extends Command
{
    public function __construct(
        private readonly MappingRecoveryService $mappingRecovery,
        private readonly ConfigProvider $configProvider,
        private readonly DataProvider $dataProvider,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->setName('everysize:order:recover')
            ->setDescription('Rebuilds lost everysize order assignments from the checkout')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be assigned');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $handledClientIds = [];

        foreach ($this->dataProvider->getSalesChannels() as $salesChannel) {
            $this->configProvider->init($salesChannel->getId());
            $clientId = $this->configProvider->getClientId();
            if ($clientId === '' || isset($handledClientIds[$clientId])) {
                continue;
            }
            $handledClientIds[$clientId] = true;

            $output->writeln(sprintf('<info>%s</info>', $salesChannel->getName()));
            $result = $this->mappingRecovery->recover($dryRun, static function (string $line) use ($output): void {
                $output->writeln('  '.$line);
            });
            if (!$dryRun) {
                $this->mappingRecovery->markDone();
            }

            $output->writeln(sprintf(
                '  %d checked, %d already assigned, %d %s, %d not found in the shop, %d skipped',
                $result['checked'],
                $result['assigned'],
                $result['recovered'],
                $dryRun ? 'would be recovered' : 'recovered',
                $result['notFound'],
                $result['mismatch']
            ));
        }

        if ($handledClientIds === []) {
            $output->writeln('No sales channel with everysize access data.');
        }

        return Command::SUCCESS;
    }
}
