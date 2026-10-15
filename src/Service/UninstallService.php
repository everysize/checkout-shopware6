<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Uninstalling without "keep data": removes the tables and the internal state
 * of this plugin. Shopware itself deletes the settings
 * (EverysizeCheckout.config.*) and the entries in `migration`.
 *
 * Never the data of the previous plugin ITSEverysizeCheckout (everysize_order
 * & co., ITSEverysizeCheckout.config.*) — it is not ours to delete.
 */
class UninstallService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function uninstall(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS `everysize_checkout_tracking_code`');
        $this->connection->executeStatement('DROP TABLE IF EXISTS `everysize_checkout_order_line_item_status`');
        $this->connection->executeStatement('DROP TABLE IF EXISTS `everysize_checkout_order`');
        $this->connection->executeStatement('DROP TABLE IF EXISTS `everysize_checkout_protocol`');

        $this->systemConfigService->delete(MappingRecoveryService::STATE_KEY);
    }
}
