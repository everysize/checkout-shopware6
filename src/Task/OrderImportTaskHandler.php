<?php

declare(strict_types=1);

namespace Everysize\Checkout\Task;

use Everysize\Checkout\Dbal\DataPersister;
use Everysize\Checkout\Service\CronjobService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: OrderImportTask::class)]
class OrderImportTaskHandler extends ScheduledTaskHandler
{
    private CronjobService $cronjobService;
    private DataPersister $dataPersister;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        CronjobService $cronjobService,
        DataPersister $dataPersister,
        LoggerInterface $logger,
    ) {
        // Shopware 6.7 requires the exception logger (6.6: optional, deprecated without).
        parent::__construct($scheduledTaskRepository, $logger);

        $this->cronjobService = $cronjobService;
        $this->dataPersister = $dataPersister;
        $this->logger = $logger;
    }

    public function run(): void
    {
        try {
            $this->cronjobService->importOrdersFromEverysize(true);
        } catch (\Throwable $e) {
            $this->logger->critical('Could not run OrderImportTask. ErrorMessage: '.$e->getMessage());
            $this->dataPersister->createProtocolEntry('Could not run OrderImportTask', $e->getMessage(), false);
        }
    }
}
