<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Api;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Dbal\Entity\Checkout\EverysizeOrderDefinition;
use Everysize\Checkout\Service\OrderExportService;
use Everysize\Checkout\Service\TrackingCodeService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "everysize" tab of an order: what the plugin knows about the
 * everysize order and what it reported to everysize. Read from the plugin's
 * tables, nothing is sent anywhere.
 *
 * Times go out as ISO 8601 in UTC (as stored); the administration shows them
 * in the user's time zone, like every other date there.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class EverysizeOrderApiController extends AbstractController
{
    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_TRACKING = 'tracking';
    public const KIND_CANCEL = 'cancel';
    public const KIND_RETOUR = 'retour';

    public const RESULT_SENT = 'sent';

    /** Revocation reasons the export sends (CronjobService). */
    private const REASONS = [
        OrderExportService::LINE_ITEM_STATUS_CANCEL => 'CUSTOMER_REVOKE',
        OrderExportService::LINE_ITEM_STATUS_RETOUR => 'RETOUR',
    ];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[Route(path: '/api/_action/everysize/order/{orderId}', name: 'api.action.everysize.order', defaults: ['_acl' => ['order.viewer']], methods: ['GET'])]
    public function order(string $orderId, Context $context): JsonResponse
    {
        if (!Uuid::isValid($orderId)) {
            return new JsonResponse(['everysizeOrder' => null]);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT `id`, `everysize_transaction_id`, `original_email`, `order_number_reported_at`, `created_at`
             FROM `everysize_checkout_order`
             WHERE `order_id` = UNHEX(:orderId) AND `order_version_id` = UNHEX(:liveVersion)
             LIMIT 1',
            ['orderId' => $orderId, 'liveVersion' => Defaults::LIVE_VERSION]
        );

        if ($row === false) {
            return new JsonResponse(['everysizeOrder' => null]);
        }

        // The customer's own address: only for users who may read the entity,
        // as before, when the administration read it from there.
        $mayReadEmail = $context->isAllowed(EverysizeOrderDefinition::ENTITY_NAME.':'.AclRoleDefinition::PRIVILEGE_READ);

        return new JsonResponse([
            'everysizeOrder' => [
                'everysizeOrderId' => (string) $row['everysize_transaction_id'],
                'importedAt' => self::isoTime($row['created_at']),
                'orderNumberReportedAt' => self::isoTime($row['order_number_reported_at']),
                'originalEmail' => $mayReadEmail ? ($row['original_email'] ?: null) : null,
            ],
            'reports' => $this->getReports($row['id']),
        ]);
    }

    /**
     * Shipping (once per order), tracking codes, cancellations and returns
     * (per item), oldest first.
     *
     * @return list<array{time: ?string, kind: string, item: string, reason: string, result: string}>
     */
    private function getReports(string $everysizeOrderId): array
    {
        $reports = [];

        $statuses = $this->connection->fetchAllAssociative(
            "SELECT s.status, s.result, s.created_at,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(li.payload, '$.productNumber')), li.label, '') AS item
             FROM `everysize_checkout_order_line_item_status` s
             LEFT JOIN `order_line_item` li ON (li.id = s.line_item_id AND li.version_id = UNHEX(:liveVersion))
             WHERE s.everysize_order_id = :id
             ORDER BY s.created_at, s.status",
            ['id' => $everysizeOrderId, 'liveVersion' => Defaults::LIVE_VERSION]
        );

        $fulfillment = null;
        foreach ($statuses as $status) {
            $result = $status['result'] ?: self::RESULT_SENT;
            if ($status['status'] === OrderExportService::LINE_ITEM_STATUS_SEND) {
                // One shipping report per order; the plugin marks every item.
                $fulfillment ??= [
                    'time' => self::isoTime($status['created_at']),
                    'kind' => self::KIND_FULFILLMENT,
                    'item' => '',
                    'reason' => '',
                    'result' => $result,
                ];
                continue;
            }

            if (!isset(self::REASONS[$status['status']])) {
                continue;
            }

            $reports[] = [
                'time' => self::isoTime($status['created_at']),
                'kind' => $status['status'] === OrderExportService::LINE_ITEM_STATUS_CANCEL ? self::KIND_CANCEL : self::KIND_RETOUR,
                'item' => (string) $status['item'],
                // A recovered status was not sent by this shop; its reason is unknown.
                'reason' => $result === OrderExportService::RESULT_PRESENT ? '' : self::REASONS[$status['status']],
                'result' => $result,
            ];
        }

        if ($fulfillment !== null) {
            $reports[] = $fulfillment;
        }

        $codes = $this->connection->fetchAllAssociative(
            'SELECT `tracking_code`, `result`, `created_at` FROM `everysize_checkout_tracking_code`
             WHERE `everysize_order_id` = :id ORDER BY `created_at`',
            ['id' => $everysizeOrderId]
        );
        foreach ($codes as $code) {
            $reports[] = [
                'time' => self::isoTime($code['created_at']),
                'kind' => self::KIND_TRACKING,
                'item' => (string) $code['tracking_code'],
                'reason' => '',
                'result' => \in_array($code['result'], [TrackingCodeService::RESULT_PRESENT, TrackingCodeService::RESULT_SKIPPED], true)
                    ? $code['result']
                    : self::RESULT_SENT,
            ];
        }

        // Oldest first; at the same time shipping before its tracking code
        // before cancellations and returns, as the export sends them.
        $order = [self::KIND_FULFILLMENT => 0, self::KIND_TRACKING => 1, self::KIND_CANCEL => 2, self::KIND_RETOUR => 3];
        usort($reports, static fn (array $a, array $b): int => [(string) $a['time'], $order[$a['kind']]] <=> [(string) $b['time'], $order[$b['kind']]]);

        return $reports;
    }

    /** A stored time (UTC) as ISO 8601, null if not set. */
    private static function isoTime(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
