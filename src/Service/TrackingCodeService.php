<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Tracking codes reported to everysize, per order.
 *
 * The shipping report goes out once (line item status "send"), with the code
 * the delivery has at that moment. A code entered or corrected afterwards is
 * reported once more (CronjobService), within DAYS after shipping. Every code
 * is remembered here, so each goes out at most once.
 *
 * Orders shipped before this table existed (also those taken over from
 * ITSEverysizeCheckout or recovered from everysize) have no remembered code.
 * Within the window their current code is compared with the one everysize
 * has: sent only if it is missing or different there, remembered either way.
 */
class TrackingCodeService
{
    /** Reported by this plugin. */
    public const RESULT_SENT = 'sent';
    /** everysize already had this code; nothing sent. */
    public const RESULT_PRESENT = 'present';
    /** Not sent: the order is not COMPLETED at everysize (e.g. revoked). */
    public const RESULT_SKIPPED = 'skipped';

    /** A code entered later is reported up to this many days after shipping. */
    public const DAYS = 14;

    /** Shipped within DAYS means ordered within this many days — a cheap pre-filter on order_date_time. */
    private const ORDER_DAYS = 180;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param string $everysizeOrderId hex id of everysize_checkout_order
     */
    public function remember(string $everysizeOrderId, string $trackingCode, string $result): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO `everysize_checkout_tracking_code` (`id`, `everysize_order_id`, `tracking_code`, `result`, `created_at`)
             VALUES (:id, UNHEX(:everysizeOrderId), :trackingCode, :result, :createdAt)',
            [
                'id' => Uuid::randomBytes(),
                'everysizeOrderId' => $everysizeOrderId,
                'trackingCode' => mb_substr($trackingCode, 0, 255),
                'result' => $result,
                'createdAt' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );
    }

    /**
     * Orders of the sales channel whose shipping was reported, which were
     * shipped within DAYS — the time the order or a delivery entered the
     * completion state (state history), else when the shipping was reported —
     * and whose current tracking code was never remembered. Orders with a
     * reported cancellation or return, or in one of $excludedStateIds, are
     * left out.
     *
     * The code is read here, as the export reports it: the first code of the
     * delivery (an everysize order has one). Runs every five minutes, so
     * nothing is loaded for orders without a new code.
     *
     * @param list<string> $excludedStateIds hex state ids (cancelled, returned)
     *
     * @return list<array{id: string, everysize_order_id: string, everysize_transaction_id: string, tracking_code: string}>
     */
    public function getRecentlyShippedOrders(string $salesChannelId, string $completionStateId, array $excludedStateIds): array
    {
        $excluded = array_values(array_filter($excludedStateIds, static fn (string $id): bool => Uuid::isValid($id)));
        $now = time();

        // JSON_UNQUOTE() returns utf8mb4_bin; the explicit collation makes the
        // comparison with the remembered codes valid. LEFT(…, 255) as remember().
        $sql = <<<'SQL'
            SELECT c.id, c.everysize_order_id, c.everysize_transaction_id, c.tracking_code
            FROM (
            SELECT HEX(o.id) AS id, HEX(eo.id) AS everysize_order_id, eo.everysize_transaction_id, eo.id AS eo_id,
                LEFT(TRIM(CONVERT(JSON_UNQUOTE(JSON_EXTRACT((
                    SELECT od.tracking_codes FROM order_delivery od
                    WHERE od.order_id = o.id AND od.order_version_id = o.version_id
                    ORDER BY od.created_at, od.id
                    LIMIT 1
                ), '$[0]')) USING utf8mb4)) COLLATE utf8mb4_unicode_ci, 255) AS tracking_code
            FROM `order` o
            JOIN everysize_checkout_order eo ON (eo.order_id = o.id AND eo.order_version_id = o.version_id)
            WHERE o.version_id = UNHEX(:liveVersion)
            AND o.sales_channel_id = UNHEX(:salesChannelId)
            AND o.order_date_time >= :orderedSince
            %s
            AND EXISTS (
                SELECT 1 FROM everysize_checkout_order_line_item_status s
                WHERE s.everysize_order_id = eo.id AND s.status = :send
            )
            AND NOT EXISTS (
                SELECT 1 FROM everysize_checkout_order_line_item_status s
                WHERE s.everysize_order_id = eo.id AND s.status IN (:cancel, :retour)
            )
            AND COALESCE(
                (
                    SELECT MAX(h.created_at) FROM state_machine_history h
                    WHERE h.to_state_id = UNHEX(:completionState)
                    AND h.referenced_version_id = o.version_id
                    AND (
                        (h.entity_name = 'order' AND h.referenced_id = o.id)
                        OR (h.entity_name = 'order_delivery' AND h.referenced_id IN (
                            SELECT od.id FROM order_delivery od WHERE od.order_id = o.id AND od.order_version_id = o.version_id
                        ))
                    )
                ),
                (
                    SELECT MIN(s.created_at) FROM everysize_checkout_order_line_item_status s
                    WHERE s.everysize_order_id = eo.id AND s.status = :send
                )
            ) >= :shippedSince
            ) c
            LEFT JOIN everysize_checkout_tracking_code t ON (t.everysize_order_id = c.eo_id AND t.tracking_code = c.tracking_code)
            WHERE c.tracking_code <> ''
            AND t.id IS NULL
            SQL;
        $params = [
            'liveVersion' => Defaults::LIVE_VERSION,
            'salesChannelId' => $salesChannelId,
            'orderedSince' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT, $now - self::ORDER_DAYS * 86400),
            'send' => OrderExportService::LINE_ITEM_STATUS_SEND,
            'cancel' => OrderExportService::LINE_ITEM_STATUS_CANCEL,
            'retour' => OrderExportService::LINE_ITEM_STATUS_RETOUR,
            'completionState' => $completionStateId,
            'shippedSince' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT, $now - self::DAYS * 86400),
        ];
        $types = [];
        if ($excluded !== []) {
            $params['excludedStates'] = array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), $excluded);
            $types['excludedStates'] = ArrayParameterType::BINARY;
        }

        /** @var list<array{id: string, everysize_order_id: string, everysize_transaction_id: string, tracking_code: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf($sql, $excluded !== [] ? 'AND o.state_id NOT IN (:excludedStates)' : ''),
            $params,
            $types
        );

        return $rows;
    }
}
