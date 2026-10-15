<?php

declare(strict_types=1);

namespace Everysize\Checkout\Legacy;

use Doctrine\DBAL\Connection;

/**
 * The previous plugin ITSEverysizeCheckout (versions up to 2.5.1).
 *
 * Both plugins must never be active at the same time: both would fetch the
 * same orders with the same access data, and the previous plugin does not
 * know the orders imported by this one — orders would be imported twice.
 * Guarded in three places:
 *
 * - EverysizeCheckout::activate() refuses while the previous plugin is active;
 * - Subscriber\LegacyPluginSubscriber refuses to activate the previous plugin
 *   while this one is active;
 * - CronjobService does not import or export while the previous plugin is
 *   active (in case the state was changed past Shopware, e.g. in the database).
 */
final class LegacyPlugin
{
    public const TECHNICAL_NAME = 'ITSEverysizeCheckout';
    public const CONFIG_PREFIX = 'ITSEverysizeCheckout.config.';

    public const TABLE_ORDER = 'everysize_order';
    public const TABLE_LINE_ITEM_STATUS = 'everysize_order_line_item_status';
    public const TABLE_PROTOCOL = 'everysize_protocol';

    public static function isActive(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1 FROM `plugin` WHERE `name` = :name AND `active` = 1 AND `installed_at` IS NOT NULL',
            ['name' => self::TECHNICAL_NAME]
        );
    }

    public static function assertInactive(Connection $connection): void
    {
        if (self::isActive($connection)) {
            throw LegacyPluginException::legacyPluginActive();
        }
    }

    public static function tableExists(Connection $connection, string $table): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table]
        );
    }
}
