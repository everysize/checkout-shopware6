<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * - everysize_checkout_order.original_email: the customer's own address, as
 *   the checkout sends it next to the forwarding address. Shown to the
 *   merchant for information only; the order keeps the forwarding address.
 * - everysize_checkout_tracking_code: every tracking code reported per order
 *   (Service\TrackingCodeService), so a code entered or corrected after the
 *   shipping report goes out once more, and only once.
 */
class Migration1791640000TrackingCodesAndOriginalEmail extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791640000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'everysize_checkout_order', 'original_email')) {
            $connection->executeStatement(
                'ALTER TABLE `everysize_checkout_order` ADD COLUMN `original_email` VARCHAR(255) NULL AFTER `everysize_transaction_id`'
            );
        }

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_tracking_code` (
                `id` BINARY(16) NOT NULL,
                `everysize_order_id` BINARY(16) NOT NULL,
                `tracking_code` VARCHAR(255) NOT NULL,
                `result` VARCHAR(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.everysize_checkout_tracking_code.order_code` (`everysize_order_id`, `tracking_code`),
                CONSTRAINT `fk.everysize_checkout_tracking_code.order` FOREIGN KEY (`everysize_order_id`)
                    REFERENCES `everysize_checkout_order` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            )
                ENGINE = InnoDB
                DEFAULT CHARSET = utf8mb4
                COLLATE = utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
