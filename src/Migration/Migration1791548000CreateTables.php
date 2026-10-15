<?php

declare(strict_types=1);

namespace Everysize\Checkout\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Tables of the plugin, as the previous plugin ITSEverysizeCheckout left them
 * in 2.5.1, under own names:
 *
 * - everysize_checkout_order: everysize order ↔ Shopware order. One row per
 *   everysize order (unique key; ITSEverysizeCheckout had none).
 * - everysize_checkout_order_line_item_status: which status (send, cancel,
 *   retour) was already reported per line item. No foreign key on
 *   order_line_item — it is versioned, and the cascade of an admin version
 *   deleted the markers of the live order (ITSEverysizeCheckout 2.2.1).
 * - everysize_checkout_protocol: protocol shown in the administration.
 *
 * Own names instead of everysize_order & co.: the previous plugin drops its
 * tables when it is uninstalled without "keep data" — that must not hit the
 * data of this plugin. Legacy\LegacyTakeover copies the rows over.
 */
class Migration1791548000CreateTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791548000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_order` (
                `id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `order_version_id` BINARY(16) NOT NULL,
                `everysize_transaction_id` VARCHAR(255) NOT NULL,
                `created_at` DATETIME(3) NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.everysize_checkout_order.transaction_id` (`everysize_transaction_id`),
                KEY `idx.everysize_checkout_order.order` (`order_id`, `order_version_id`),
                CONSTRAINT `fk.everysize_checkout_order.order` FOREIGN KEY (`order_id`, `order_version_id`)
                    REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            )
                ENGINE = InnoDB
                DEFAULT CHARSET = utf8mb4
                COLLATE = utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_order_line_item_status` (
                `id` BINARY(16) NOT NULL,
                `everysize_order_id` BINARY(16) NOT NULL,
                `line_item_id` BINARY(16) NOT NULL,
                `status` VARCHAR(255) NOT NULL,
                `created_at` DATETIME(3) NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.everysize_checkout_order_line_item_status.order` (`everysize_order_id`),
                KEY `idx.everysize_checkout_order_line_item_status.line_item` (`line_item_id`),
                CONSTRAINT `fk.everysize_checkout_order_line_item_status.order` FOREIGN KEY (`everysize_order_id`)
                    REFERENCES `everysize_checkout_order` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            )
                ENGINE = InnoDB
                DEFAULT CHARSET = utf8mb4
                COLLATE = utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `everysize_checkout_protocol` (
                `id` BINARY(16) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `message` TEXT NULL,
                `state` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.everysize_checkout_protocol.created_at` (`created_at`)
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
