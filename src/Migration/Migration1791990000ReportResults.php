<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * For the "everysize" tab of the order (Controller\Api\EverysizeOrderApiController):
 *
 * - everysize_checkout_order.order_number_reported_at: when the shop's order
 *   number went to everysize. Empty for assignments from before (unknown).
 * - everysize_checkout_order_line_item_status.result: what became of a
 *   status. Empty = reported (all markers from before), "skipped" = not
 *   transmitted (order already revoked at everysize), "present" = known at
 *   everysize already (assignment recovered from everysize).
 */
class Migration1791990000ReportResults extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791990000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'everysize_checkout_order', 'order_number_reported_at')) {
            $connection->executeStatement(
                'ALTER TABLE `everysize_checkout_order` ADD COLUMN `order_number_reported_at` DATETIME(3) NULL AFTER `original_email`'
            );
        }

        if (!$this->columnExists($connection, 'everysize_checkout_order_line_item_status', 'result')) {
            $connection->executeStatement(
                'ALTER TABLE `everysize_checkout_order_line_item_status` ADD COLUMN `result` VARCHAR(16) NULL AFTER `status`'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
