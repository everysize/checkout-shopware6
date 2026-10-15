<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Everysize\Client;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Rebuilds lost order assignments from the checkout.
 *
 * The everysize order ID of an imported order exists only in
 * everysize_checkout_order (with the previous plugin: everysize_order). If the
 * previous plugin was uninstalled without "keep data" before this one took its
 * data over, the table is gone — and with it the shipping, cancellation and
 * return reports for those orders. The checkout still knows every order the
 * shop confirmed, with the order number the shop sent back.
 *
 * For each such order (acknowledged=true): find the Shopware order with that
 * order number (live version) whose customer email is the one of the everysize
 * order — the forwarding address everysize gives each order, so a number of
 * another order cannot match by chance — and create the assignment. What the
 * checkout already knows is marked as reported, so it is not sent again:
 * completed → shipping; revoked → cancellation and return.
 *
 * Runs once per access data on the first import after activation (in a normal
 * switch-over everything is assigned already and nothing changes) and on
 * demand: bin/console everysize:order:recover [--dry-run].
 */
class MappingRecoveryService
{
    public const STATE_KEY = 'EverysizeCheckout.state.recoveredClients';

    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly ClientService $clientService,
        private readonly Client $client,
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * For the access data the ConfigProvider is initialised with.
     */
    public function isDone(): bool
    {
        return \in_array($this->getStateId(), $this->getDoneStateIds(), true);
    }

    public function markDone(): void
    {
        $done = $this->getDoneStateIds();
        $done[] = $this->getStateId();

        $this->systemConfigService->set(self::STATE_KEY, array_values(array_unique($done)));
    }

    /**
     * For the access data the ConfigProvider is initialised with. Throws if
     * the checkout cannot be read; what was assigned until then stays.
     *
     * @param callable(string): void|null $report receives one line per order that was or would be assigned or skipped
     *
     * @return array{checked: int, assigned: int, recovered: int, notFound: int, mismatch: int}
     */
    public function recover(bool $dryRun, ?callable $report = null): array
    {
        $result = ['checked' => 0, 'assigned' => 0, 'recovered' => 0, 'notFound' => 0, 'mismatch' => 0];
        $report ??= static function (string $line): void {
        };

        $assigned = array_flip($this->connection->fetchFirstColumn(
            'SELECT `everysize_transaction_id` FROM `everysize_checkout_order`'
        ));
        $assignedOrders = array_flip($this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(`order_id`)) FROM `everysize_checkout_order`'
        ));

        $page = 0;
        do {
            $response = $this->clientService->getAcknowledgedOrders($page, self::PAGE_SIZE);

            foreach ($response['content'] as $item) {
                $everysizeOrderId = trim((string) ($item['everysizeOrderId'] ?? ''));
                $orderNumber = trim((string) ($item['merchantOrderNumber'] ?? ''));
                if ($everysizeOrderId === '' || $orderNumber === '') {
                    continue;
                }

                ++$result['checked'];
                if (isset($assigned[$everysizeOrderId])) {
                    ++$result['assigned'];
                    continue;
                }

                $email = trim((string) ($item['customer']['email'] ?? ''));
                $candidates = $this->findOrders($orderNumber);
                if ($candidates === []) {
                    ++$result['notFound'];
                    $report(sprintf('%s: order %s not found in the shop', $everysizeOrderId, $orderNumber));
                    continue;
                }

                $matching = array_values(array_filter(
                    $candidates,
                    static fn (array $order): bool => $email !== '' && strcasecmp(trim((string) $order['email']), $email) === 0
                ));
                if (\count($matching) !== 1 || isset($assignedOrders[$matching[0]['id']])) {
                    ++$result['mismatch'];
                    $report(sprintf('%s: order %s skipped (customer email does not match or order already assigned)', $everysizeOrderId, $orderNumber));
                    continue;
                }

                $status = strtoupper((string) ($item['status'] ?? ''));
                if (!$dryRun) {
                    $this->assign($matching[0]['id'], $everysizeOrderId, $status);
                }

                $assigned[$everysizeOrderId] = true;
                $assignedOrders[$matching[0]['id']] = true;
                ++$result['recovered'];
                $report(sprintf('%s → order %s (%s)%s', $everysizeOrderId, $orderNumber, $status, $dryRun ? ' [dry run]' : ''));
            }

            ++$page;
        } while ($page < $response['totalPages']);

        return $result;
    }

    /**
     * @return list<array{id: string, email: string}>
     */
    private function findOrders(string $orderNumber): array
    {
        /** @var list<array{id: string, email: string}> $orders */
        $orders = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT LOWER(HEX(o.`id`)) AS `id`, oc.`email`
            FROM `order` o
            INNER JOIN `order_customer` oc ON (oc.`order_id` = o.`id` AND oc.`order_version_id` = o.`version_id`)
            WHERE o.`order_number` = :orderNumber AND o.`version_id` = UNHEX(:liveVersion)
            SQL, [
            'orderNumber' => $orderNumber,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);

        return $orders;
    }

    private function assign(string $orderId, string $everysizeOrderId, string $status): void
    {
        $statuses = match ($status) {
            'COMPLETED' => [OrderExportService::LINE_ITEM_STATUS_SEND],
            'REVOKED', 'REVOKING' => [OrderExportService::LINE_ITEM_STATUS_CANCEL, OrderExportService::LINE_ITEM_STATUS_RETOUR],
            default => [],
        };

        $this->connection->transactional(function (Connection $connection) use ($orderId, $everysizeOrderId, $statuses): void {
            $mappingId = Uuid::randomBytes();
            $connection->executeStatement(
                'INSERT INTO `everysize_checkout_order` (`id`, `order_id`, `order_version_id`, `everysize_transaction_id`, `created_at`)
                VALUES (:id, UNHEX(:orderId), UNHEX(:liveVersion), :everysizeOrderId, NOW(3))',
                [
                    'id' => $mappingId,
                    'orderId' => $orderId,
                    'liveVersion' => Defaults::LIVE_VERSION,
                    'everysizeOrderId' => $everysizeOrderId,
                ]
            );

            if ($statuses === []) {
                return;
            }

            $lineItemIds = $connection->fetchFirstColumn(
                'SELECT `id` FROM `order_line_item` WHERE `order_id` = UNHEX(:orderId) AND `version_id` = UNHEX(:liveVersion)',
                ['orderId' => $orderId, 'liveVersion' => Defaults::LIVE_VERSION]
            );

            foreach ($lineItemIds as $lineItemId) {
                foreach ($statuses as $lineItemStatus) {
                    $connection->executeStatement(
                        'INSERT INTO `everysize_checkout_order_line_item_status` (`id`, `everysize_order_id`, `line_item_id`, `status`, `result`, `created_at`)
                        VALUES (:id, :everysizeOrderId, :lineItemId, :status, :result, NOW(3))',
                        [
                            'id' => Uuid::randomBytes(),
                            'everysizeOrderId' => $mappingId,
                            'lineItemId' => $lineItemId,
                            'status' => $lineItemStatus,
                            'result' => OrderExportService::RESULT_PRESENT,
                        ]
                    );
                }
            }
        });
    }

    /**
     * Per API environment and client ID — a run against a test environment
     * says nothing about production. Hashed: the state is no place for access data.
     */
    private function getStateId(): string
    {
        return sha1($this->client->getApiUrl().'|'.$this->configProvider->getClientId());
    }

    /**
     * @return list<string>
     */
    private function getDoneStateIds(): array
    {
        $done = $this->systemConfigService->get(self::STATE_KEY);

        return \is_array($done) ? array_values(array_filter($done, 'is_string')) : [];
    }
}
