<?php

declare(strict_types=1);

namespace Everysize\Checkout\Legacy;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Takes over what the previous plugin ITSEverysizeCheckout left in the shop:
 *
 * - settings ITSEverysizeCheckout.config.* → EverysizeCheckout.config.*, for
 *   every sales channel — only as long as this plugin has no client ID yet, so
 *   settings made here are never overwritten;
 * - order assignments (everysize_order), already reported line item states
 *   (everysize_order_line_item_status) and the protocol (everysize_protocol)
 *   into the tables of this plugin.
 *
 * Copies, never moves: the previous plugin's data stays as it was, so it can
 * still be switched back. Runs after install, on every activation and update;
 * each run only adds what is missing (an order once per everysize order ID),
 * so what the previous plugin imported between installation and deactivation
 * arrives on activation.
 *
 * What it does not take over: the custom field set "everysize" (field
 * everysize_transaction_id on order transactions, never written); the
 * everysize_* values in order_transaction.custom_fields are data of the
 * transactions and stay where they are.
 */
final class LegacyTakeover
{
    public const CONFIG_PREFIX = 'EverysizeCheckout.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @return array{settings: int, orders: int, states: int, protocol: int}
     */
    public function run(): array
    {
        $result = [
            'settings' => $this->takeOverSettings(),
            'orders' => 0,
            'states' => 0,
            'protocol' => 0,
        ];

        if (LegacyPlugin::tableExists($this->connection, LegacyPlugin::TABLE_ORDER)) {
            $result['orders'] = $this->copyOrders();

            if (LegacyPlugin::tableExists($this->connection, LegacyPlugin::TABLE_LINE_ITEM_STATUS)) {
                $result['states'] = $this->copyLineItemStates();
            }
        }

        if (LegacyPlugin::tableExists($this->connection, LegacyPlugin::TABLE_PROTOCOL)) {
            $result['protocol'] = $this->copyProtocol();
        }

        if (array_sum($result) > 0) {
            $this->writeProtocol(sprintf(
                'Taken over from ITSEverysizeCheckout: %d settings, %d order assignments, %d reported line item states, %d protocol entries',
                $result['settings'],
                $result['orders'],
                $result['states'],
                $result['protocol']
            ), '', true);
        }

        return $result;
    }

    public function writeProtocol(string $title, string $message, bool $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO `everysize_checkout_protocol` (`id`, `title`, `message`, `state`, `created_at`) VALUES (:id, :title, :message, :state, NOW(3))',
            [
                'id' => Uuid::randomBytes(),
                'title' => mb_substr($title, 0, 255),
                'message' => $message,
                'state' => $state ? 1 : 0,
            ]
        );
    }

    private function takeOverSettings(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `configuration_key`, `configuration_value`, LOWER(HEX(`sales_channel_id`)) AS `sales_channel_id`
            FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => LegacyPlugin::CONFIG_PREFIX.'%']
        );

        if ($rows === [] || $this->hasClientId()) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $value = json_decode((string) $row['configuration_value'], true);
            if (!\is_array($value) || !isset($value['_value'])) {
                continue;
            }

            // Through the service, not SQL: it invalidates Shopware's config cache.
            $this->systemConfigService->set(
                self::CONFIG_PREFIX.substr((string) $row['configuration_key'], \strlen(LegacyPlugin::CONFIG_PREFIX)),
                $value['_value'],
                $row['sales_channel_id'] ?: null
            );
            ++$count;
        }

        return $count;
    }

    /**
     * A client ID on any level means the plugin is configured — by the
     * merchant or by an earlier takeover.
     */
    private function hasClientId(): bool
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::CONFIG_PREFIX.'clientId']
        );

        foreach ($values as $value) {
            $decoded = json_decode((string) $value, true);
            if (\is_array($decoded) && trim((string) ($decoded['_value'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One row per everysize order — ITSEverysizeCheckout had no unique key —
     * and only for orders that still exist (the foreign key of the new table).
     */
    private function copyOrders(): int
    {
        return (int) $this->connection->executeStatement(<<<'SQL'
            INSERT INTO `everysize_checkout_order`
                (`id`, `order_id`, `order_version_id`, `everysize_transaction_id`, `created_at`, `updated_at`)
            SELECT l.`id`, l.`order_id`, l.`order_version_id`, l.`everysize_transaction_id`, COALESCE(l.`created_at`, NOW(3)), l.`updated_at`
            FROM `everysize_order` l
            INNER JOIN (
                SELECT MIN(x.`id`) AS `id`
                FROM `everysize_order` x
                INNER JOIN `order` o ON (o.`id` = x.`order_id` AND o.`version_id` = x.`order_version_id`)
                GROUP BY x.`everysize_transaction_id`
            ) pick ON (pick.`id` = l.`id`)
            WHERE NOT EXISTS (
                SELECT 1 FROM `everysize_checkout_order` n
                WHERE n.`id` = l.`id` OR n.`everysize_transaction_id` = l.`everysize_transaction_id`
            )
            SQL);
    }

    /**
     * Joined over the everysize order ID, not the row ID, so the markers also
     * reach an assignment that was created here in another way.
     */
    private function copyLineItemStates(): int
    {
        return (int) $this->connection->executeStatement(<<<'SQL'
            INSERT INTO `everysize_checkout_order_line_item_status`
                (`id`, `everysize_order_id`, `line_item_id`, `status`, `created_at`, `updated_at`)
            SELECT s.`id`, n.`id`, s.`line_item_id`, s.`status`, COALESCE(s.`created_at`, NOW(3)), s.`updated_at`
            FROM `everysize_order_line_item_status` s
            INNER JOIN `everysize_order` l ON (l.`id` = s.`everysize_order_id`)
            INNER JOIN `everysize_checkout_order` n ON (n.`everysize_transaction_id` = l.`everysize_transaction_id`)
            WHERE NOT EXISTS (
                SELECT 1 FROM `everysize_checkout_order_line_item_status` x
                WHERE x.`id` = s.`id`
                   OR (x.`everysize_order_id` = n.`id` AND x.`line_item_id` = s.`line_item_id` AND x.`status` = s.`status`)
            )
            SQL);
    }

    private function copyProtocol(): int
    {
        return (int) $this->connection->executeStatement(<<<'SQL'
            INSERT INTO `everysize_checkout_protocol` (`id`, `title`, `message`, `state`, `created_at`, `updated_at`)
            SELECT p.`id`, p.`title`, p.`message`, p.`state`, COALESCE(p.`created_at`, NOW(3)), p.`updated_at`
            FROM `everysize_protocol` p
            WHERE NOT EXISTS (SELECT 1 FROM `everysize_checkout_protocol` n WHERE n.`id` = p.`id`)
            SQL);
    }
}
