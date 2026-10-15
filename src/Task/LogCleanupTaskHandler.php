<?php

declare(strict_types=1);

namespace Everysize\Checkout\Task;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Dbal\DataPersister;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: LogCleanupTask::class)]
class LogCleanupTaskHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private DataPersister $dataPersister;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        DataPersister $dataPersister,
        LoggerInterface $logger,
    ) {
        // Shopware 6.7 requires the exception logger (6.6: optional, deprecated without).
        parent::__construct($scheduledTaskRepository, $logger);

        $this->connection = $connection;
        $this->dataPersister = $dataPersister;
        $this->logger = $logger;
    }

    public function run(): void
    {
        try {
            $time = new \DateTime();
            $time->modify(sprintf('-%s days', 31));

            do {
                $result = $this->connection->executeStatement(
                    'DELETE FROM everysize_checkout_protocol WHERE created_at <= :timestamp LIMIT 500',
                    ['timestamp' => $time->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
                );
            } while ($result > 0);
        } catch (\Throwable $e) {
            $this->logger->critical('Could not run LogCleanupTask. ErrorMessage: '.$e->getMessage());
            $this->dataPersister->createProtocolEntry('Could not run LogCleanupTask', $e->getMessage(), false);
        }
    }
}
