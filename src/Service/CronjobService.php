<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Dbal\DataPersister;
use Everysize\Checkout\Dbal\DataProvider;
use Everysize\Checkout\Legacy\LegacyPlugin;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class CronjobService
{
    private const LEGACY_PLUGIN_ACTIVE = 'ITSEverysizeCheckout is active: no import or export until it is deactivated';

    private ConfigProvider $configProvider;
    private ClientService $clientService;
    private OrderImportService $importService;
    private OrderExportService $exportService;
    private DataProvider $dataProvider;
    private DataPersister $dataPersister;
    private Connection $connection;
    private LoggerInterface $logger;
    private MappingRecoveryService $mappingRecovery;
    private TrackingCodeService $trackingCodes;

    public function __construct(
        ConfigProvider $configProvider,
        ClientService $clientService,
        OrderImportService $importService,
        OrderExportService $exportService,
        DataProvider $dataProvider,
        DataPersister $dataPersister,
        Connection $connection,
        LoggerInterface $logger,
        MappingRecoveryService $mappingRecovery,
        TrackingCodeService $trackingCodes,
    ) {
        $this->configProvider = $configProvider;
        $this->clientService = $clientService;
        $this->importService = $importService;
        $this->exportService = $exportService;
        $this->dataProvider = $dataProvider;
        $this->dataPersister = $dataPersister;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->mappingRecovery = $mappingRecovery;
        $this->trackingCodes = $trackingCodes;
    }

    public function importOrdersFromEverysize(bool $isScheduledTask = false): array
    {
        $result = [];
        $transferred = [];
        $errors = [];

        if ($isScheduledTask && $this->configProvider->isDeactivateScheduledTask()) {
            return $result;
        }

        if ($this->isLegacyPluginActive()) {
            return [self::LEGACY_PLUGIN_ACTIVE];
        }

        $handledOrderNumbers = [];
        $salesChannels = $this->dataProvider->getSalesChannels();
        foreach ($salesChannels as $salesChannel) {
            /* @var SalesChannelEntity $salesChannel */
            $this->configProvider->init($salesChannel->getId());
            $errors[$salesChannel->getId()] = [];

            if (empty($this->configProvider->getClientId())) {
                continue;
            }

            if ($importingSalesChannel = $this->getOtherImportingSalesChannel($salesChannel, $salesChannels)) {
                $result[$salesChannel->getId()] = sprintf(
                    'Orders are imported into SalesChannel %s (SalesChannel: %s)',
                    $importingSalesChannel->getName(),
                    $salesChannel->getName()
                );
                continue;
            }

            $this->recoverAssignmentsOnce($salesChannel);

            try {
                $orders = $this->clientService->getOrders();
            } catch (\Throwable $e) {
                $result[$salesChannel->getId()] = sprintf('Import orders failed with error %s (SalesChannel: %s)', $e->getMessage(), $salesChannel->getName());
                $errors[$salesChannel->getId()][] = $e->getMessage();
                continue;
            }

            $importedOrders = 0;
            if (!empty($orders)) {
                $this->importService->setConfigProvider($this->configProvider);
                foreach ($orders as $orderItem) {
                    try {
                        if (!is_array($orderItem) || !isset($orderItem['everysizeOrderId'])) {
                            continue 2;
                        }

                        if (!$this->dataProvider->isEverysizeOrder($orderItem['everysizeOrderId'])) {
                            $orderNumber = $this->importService->importOrder($orderItem, $salesChannel);
                            ++$importedOrders;
                        } else {
                            $orderNumber = $this->dataProvider->getOrderNumberByEverysizeTransactionId($orderItem['everysizeOrderId']);
                        }

                        if ($orderNumber && !isset($handledOrderNumbers[$orderNumber])) {
                            if ($this->clientService->sendOrderNumber($orderItem['everysizeOrderId'], $orderNumber)) {
                                $handledOrderNumbers[$orderNumber] = true;
                                $this->rememberOrderNumberReported($orderItem['everysizeOrderId']);
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[$salesChannel->getId()][] = $orderItem['everysizeOrderId'].': '.$e->getMessage();
                        $this->logger->critical($e->getMessage());
                    }
                }
            }

            $result[$salesChannel->getId()] = sprintf(
                'Imported %s of %s orders from everysize (SalesChannel: %s)',
                $importedOrders,
                count($orders),
                $salesChannel->getName()
            );
            $transferred[$salesChannel->getId()] = count($orders);
        }

        foreach ($result as $salesChannelId => $title) {
            $errorMessage = '';
            if (isset($errors[$salesChannelId]) && count($errors[$salesChannelId])) {
                $errorMessage = implode("\n", $errors[$salesChannelId]);
            }

            // Only runs that moved something or failed belong in the protocol —
            // with import and export every 5 minutes, "0 of 0" entries buried
            // the relevant ones (hundreds a day).
            if ($errorMessage === '' && empty($transferred[$salesChannelId])) {
                continue;
            }

            $this->dataPersister->createProtocolEntry($title, $errorMessage, empty($errorMessage));
        }

        return $result;
    }

    /**
     * Sales channels without access data of their own inherit the global
     * ones, so several channels fetch the same orders, and each order lands
     * in the channel processed first. The setting "importSalesChannel" names
     * the channel that imports the orders of these access data; the others
     * skip. Empty (default): every channel imports, as before 2.4.0.
     *
     * The setting is followed only if that channel exists, uses the same
     * access data and imports them itself — otherwise no channel would. A
     * channel with access data of its own (another everysize shop) inherits
     * the setting but is not meant by it and imports as usual.
     */
    private function getOtherImportingSalesChannel(SalesChannelEntity $salesChannel, SalesChannelCollection $salesChannels): ?SalesChannelEntity
    {
        $targetId = $this->configProvider->getImportSalesChannel();
        if ($targetId === '' || $targetId === $salesChannel->getId()) {
            return null;
        }

        $target = $salesChannels->get($targetId);
        if ($target !== null && $this->configProvider->getValueOf($targetId, 'clientId') !== $this->configProvider->getClientId()) {
            return null;
        }

        if ($target === null || !\in_array($this->configProvider->getValueOf($targetId, 'importSalesChannel'), ['', $targetId], true)) {
            $this->logger->warning(sprintf(
                'Setting "importSalesChannel" of SalesChannel %s ignored: SalesChannel %s does not exist or imports into another channel itself',
                $salesChannel->getName(),
                $targetId
            ));

            return null;
        }

        return $target;
    }

    public function transferOrderStatesToEverysize(bool $isScheduledTask = false): array
    {
        $salesChannels = $this->dataProvider->getSalesChannels();
        $result = [];
        $transferred = [];
        $errors = [];

        if ($isScheduledTask && $this->configProvider->isDeactivateScheduledTask()) {
            return $result;
        }

        if ($this->isLegacyPluginActive()) {
            return [self::LEGACY_PLUGIN_ACTIVE];
        }

        foreach ($salesChannels as $salesChannel) {
            /* @var SalesChannelEntity $salesChannel */
            $this->configProvider->init($salesChannel->getId());

            if (empty($this->configProvider->getClientId())) {
                continue;
            }

            // Handle Completed Orders
            $transmittedOrderStates = 0;
            $salesChannelIndexSend = $salesChannel->getId().'-send';
            $ordersForShippingTransfer = [];
            $errors[$salesChannelIndexSend] = [];

            try {
                if (!empty($this->configProvider->getOrderCompletionState())) {
                    $ordersForShippingTransfer = $this->getOrdersForShippingTransmission($this->configProvider->getOrderCompletionState(), $salesChannel->getId());
                    if (count($ordersForShippingTransfer) > 0) {
                        foreach ($ordersForShippingTransfer as $orderForShippingTransfer) {
                            try {
                                $order = $this->dataProvider->getOrderById(strtolower($orderForShippingTransfer['id']));

                                // The checkout sets every order it gets a fulfillment for to
                                // "completed" — also one that was revoked there after the
                                // import (PayPal refund, partner backoffice). Such an order is
                                // marked as handled without transmitting, so it is reported
                                // once instead of every run.
                                $everysizeOrder = $this->clientService->getOrder($orderForShippingTransfer['everysize_transaction_id']);
                                if (strtoupper((string) $everysizeOrder['status']) === 'REVOKED') {
                                    foreach ($order->getLineItems() as $lineItem) {
                                        $this->exportService->storeLineItemFulfillment(strtolower($orderForShippingTransfer['everysize_order_id']), $lineItem, OrderExportService::RESULT_SKIPPED);
                                    }
                                    $errors[$salesChannelIndexSend][] = sprintf('Send-Order %s is revoked at everysize, fulfillment not transmitted', $orderForShippingTransfer['everysize_transaction_id']);
                                    continue;
                                }

                                $trackingCode = self::trackingCode($order);

                                if ($this->clientService->fulfillOrder(
                                    $orderForShippingTransfer['everysize_transaction_id'],
                                    $this->configProvider->getCarrier(),
                                    $trackingCode
                                )) {
                                    foreach ($order->getLineItems() as $lineItem) {
                                        $this->exportService->storeLineItemFulfillment(strtolower($orderForShippingTransfer['everysize_order_id']), $lineItem);
                                    }
                                    // fulfillOrder() sends the code only together with a carrier.
                                    if ($trackingCode !== '' && $this->configProvider->getCarrier() !== '') {
                                        $this->trackingCodes->remember(strtolower($orderForShippingTransfer['everysize_order_id']), $trackingCode, TrackingCodeService::RESULT_SENT);
                                    }
                                    ++$transmittedOrderStates;
                                }
                            } catch (\Throwable $e) {
                                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                                $errors[$salesChannelIndexSend][] = sprintf('Send-Order %s failed with error %s', $orderForShippingTransfer['everysize_transaction_id'], $e->getMessage());
                            }
                        }
                    }
                } else {
                    $this->logger->critical('Order Completion State not selected in plugin settings');
                    $errors[$salesChannelIndexSend][] = 'Order Completion State not selected in plugin settings';
                }

                $result[$salesChannelIndexSend] = sprintf(
                    'Exported %s of %s Send-Orders to everysize (SalesChannel: %s)',
                    $transmittedOrderStates,
                    \count($ordersForShippingTransfer),
                    $salesChannel->getName()
                );
                $transferred[$salesChannelIndexSend] = \count($ordersForShippingTransfer);
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                $errors[$salesChannelIndexSend][] = sprintf('Unexpected Error: %s', $e->getMessage());
                $result[$salesChannelIndexSend] = sprintf('Send-Order Export failed (SalesChannel: %s)', $salesChannel->getName());
            }

            // Handle tracking codes entered or corrected after the shipping report
            $salesChannelIndexTracking = $salesChannel->getId().'-tracking';
            $errors[$salesChannelIndexTracking] = [];
            [$checkedCodes, $transmittedCodes] = $this->transferLateTrackingCodes($salesChannel, $errors[$salesChannelIndexTracking]);
            if ($checkedCodes > 0 || $errors[$salesChannelIndexTracking] !== []) {
                $result[$salesChannelIndexTracking] = sprintf(
                    'Exported %s of %s tracking codes entered after shipping to everysize (SalesChannel: %s)',
                    $transmittedCodes,
                    $checkedCodes,
                    $salesChannel->getName()
                );
                $transferred[$salesChannelIndexTracking] = $checkedCodes;
            }

            // Handle Cancelled Orders
            $transmittedOrderStates = 0;
            $salesChannelIndexCanceled = $salesChannel->getId().'-canceled';
            $ordersForCancelTransfer = [];
            $errors[$salesChannelIndexCanceled] = [];

            try {
                if (!empty($this->configProvider->getOrderCancellationState())) {
                    $ordersForCancelTransfer = $this->getOrdersForStateTransmission($this->configProvider->getOrderCancellationState(), $salesChannel->getId(), OrderExportService::LINE_ITEM_STATUS_CANCEL);
                    if (count($ordersForCancelTransfer) > 0) {
                        foreach ($ordersForCancelTransfer as $orderForCancelTransfer) {
                            try {
                                $order = $this->dataProvider->getOrderById(strtolower($orderForCancelTransfer['id']));
                                $cancelledItems = 0;
                                foreach ($order->getLineItems() as $lineItem) {
                                    if ($this->clientService->revokeOrder(
                                        $orderForCancelTransfer['everysize_transaction_id'],
                                        $lineItem,
                                        'CUSTOMER_REVOKE'
                                    )) {
                                        $this->exportService->storeLineItemCancellation(strtolower($orderForCancelTransfer['everysize_order_id']), $lineItem);
                                        ++$cancelledItems;
                                    }
                                }

                                if ($cancelledItems === $order->getLineItems()->count()) {
                                    ++$transmittedOrderStates;
                                }
                            } catch (\Throwable $e) {
                                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                                $errors[$salesChannelIndexCanceled][] = sprintf('Canceled-Order %s failed with error %s', $orderForCancelTransfer['everysize_transaction_id'], $e->getMessage());
                            }
                        }
                    }
                } else {
                    $this->logger->critical('Order Cancellation State not selected in plugin settings');
                    $errors[$salesChannelIndexCanceled][] = 'Order Cancellation State not selected in plugin settings';
                }

                $result[$salesChannelIndexCanceled] = sprintf(
                    'Exported %s of %s Canceled-Orders to everysize (SalesChannel: %s)',
                    $transmittedOrderStates,
                    \count($ordersForCancelTransfer),
                    $salesChannel->getName()
                );
                $transferred[$salesChannelIndexCanceled] = \count($ordersForCancelTransfer);
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                $errors[$salesChannelIndexCanceled][] = sprintf('Unexpected Error: %s', $e->getMessage());
                $result[$salesChannelIndexCanceled] = sprintf('Canceled-Order Export failed (SalesChannel: %s)', $salesChannel->getName());
            }

            // Handle Returned Orders
            $transmittedOrderStates = 0;
            $salesChannelIndexReturned = $salesChannel->getId().'-returned';
            $ordersForReturnTransfer = [];
            $errors[$salesChannelIndexReturned] = [];

            try {
                if (!empty($this->configProvider->getOrderReturnedState())) {
                    $ordersForReturnTransfer = $this->getOrdersForStateTransmission($this->configProvider->getOrderReturnedState(), $salesChannel->getId(), OrderExportService::LINE_ITEM_STATUS_RETOUR);
                    if (count($ordersForReturnTransfer) > 0) {
                        foreach ($ordersForReturnTransfer as $orderForReturnTransfer) {
                            try {
                                $order = $this->dataProvider->getOrderById(strtolower($orderForReturnTransfer['id']));
                                $returnedItems = 0;
                                foreach ($order->getLineItems() as $lineItem) {
                                    if ($this->clientService->revokeOrder(
                                        $orderForReturnTransfer['everysize_transaction_id'],
                                        $lineItem,
                                        'RETOUR'
                                    )) {
                                        $this->exportService->storeLineItemReturnal(strtolower($orderForReturnTransfer['everysize_order_id']), $lineItem);
                                        ++$returnedItems;
                                    }
                                }

                                if ($returnedItems === $order->getLineItems()->count()) {
                                    ++$transmittedOrderStates;
                                }
                            } catch (\Throwable $e) {
                                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                                $errors[$salesChannelIndexReturned][] = sprintf('Returned-Order %s failed with error %s', $orderForReturnTransfer['everysize_transaction_id'], $e->getMessage());
                            }
                        }
                    }
                } else {
                    $this->logger->critical('Order Return State not selected in plugin settings');
                    $errors[$salesChannelIndexReturned][] = 'Order Return State not selected in plugin settings';
                }

                $result[$salesChannelIndexReturned] = sprintf(
                    'Exported %s of %s Returned-Orders to everysize (SalesChannel: %s)',
                    $transmittedOrderStates,
                    \count($ordersForReturnTransfer),
                    $salesChannel->getName()
                );
                $transferred[$salesChannelIndexReturned] = \count($ordersForReturnTransfer);
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                $errors[$salesChannelIndexReturned][] = sprintf('Unexpected Error: %s', $e->getMessage());
                $result[$salesChannelIndexReturned] = sprintf('Returned-Order Export failed (SalesChannel: %s)', $salesChannel->getName());
            }
        }

        foreach ($result as $salesChannelId => $title) {
            $errorMessage = '';
            if (isset($errors[$salesChannelId]) && count($errors[$salesChannelId])) {
                $errorMessage = implode("\n", $errors[$salesChannelId]);
            }

            // Only runs that moved something or failed belong in the protocol —
            // with import and export every 5 minutes, "0 of 0" entries buried
            // the relevant ones (hundreds a day).
            if ($errorMessage === '' && empty($transferred[$salesChannelId])) {
                continue;
            }

            $this->dataPersister->createProtocolEntry($title, $errorMessage, empty($errorMessage));
        }

        return $result;
    }

    /**
     * A tracking code entered or corrected after the shipping report goes out
     * once more (TrackingCodeService), for orders shipped within
     * TrackingCodeService::DAYS. Only while the order is COMPLETED at
     * everysize: a fulfillment sets any order there to COMPLETED, also a
     * revoked one. Only if everysize does not have that code yet — orders
     * shipped before the codes were remembered (also taken over or recovered
     * ones) mostly do; then the code is just remembered. The checkout takes a
     * repeated fulfillment as an update of carrier and code, without a mail to
     * the customer.
     *
     * @param list<string> $errors
     *
     * @return array{int, int} codes checked at everysize, codes sent
     */
    private function transferLateTrackingCodes(SalesChannelEntity $salesChannel, array &$errors): array
    {
        $carrier = $this->configProvider->getCarrier();
        $completionState = $this->configProvider->getOrderCompletionState();
        if ($carrier === '' || $completionState === '') {
            return [0, 0];
        }

        $checked = 0;
        $sent = 0;
        try {
            $orders = $this->trackingCodes->getRecentlyShippedOrders(
                $salesChannel->getId(),
                $completionState,
                array_filter([$this->configProvider->getOrderCancellationState(), $this->configProvider->getOrderReturnedState()])
            );
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
            $errors[] = sprintf('Tracking codes: unexpected error %s', $e->getMessage());

            return [0, 0];
        }

        // Only orders with a code never remembered; the query reads the code, nothing is loaded.
        foreach ($orders as $row) {
            $everysizeOrderId = strtolower($row['everysize_order_id']);
            $trackingCode = $row['tracking_code'];
            try {
                ++$checked;
                $everysizeOrder = $this->clientService->getOrder($row['everysize_transaction_id']);
                $status = strtoupper((string) ($everysizeOrder['status'] ?? ''));
                if ($status !== 'COMPLETED') {
                    $this->trackingCodes->remember($everysizeOrderId, $trackingCode, TrackingCodeService::RESULT_SKIPPED);
                    $errors[] = sprintf(
                        'Send-Order %s is %s at everysize, tracking code %s not transmitted',
                        $row['everysize_transaction_id'],
                        $status !== '' ? $status : 'unknown',
                        $trackingCode
                    );
                    continue;
                }

                // ExternalOrder: fulfillment.tracking is {code, carrier}, or [] without a code.
                $known = $everysizeOrder['fulfillment']['tracking']['code'] ?? null;
                if (\is_scalar($known) && trim((string) $known) === $trackingCode) {
                    $this->trackingCodes->remember($everysizeOrderId, $trackingCode, TrackingCodeService::RESULT_PRESENT);
                    continue;
                }

                if ($this->clientService->fulfillOrder($row['everysize_transaction_id'], $carrier, $trackingCode)) {
                    $this->trackingCodes->remember($everysizeOrderId, $trackingCode, TrackingCodeService::RESULT_SENT);
                    ++$sent;
                }
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
                $errors[] = sprintf('Tracking code of Send-Order %s failed with error %s', $row['everysize_transaction_id'], $e->getMessage());
            }
        }

        return [$checked, $sent];
    }

    /** For the order tab: when the order number went to everysize (the first time). */
    private function rememberOrderNumberReported(string $everysizeTransactionId): void
    {
        $this->connection->executeStatement(
            'UPDATE `everysize_checkout_order` SET `order_number_reported_at` = :now
             WHERE `everysize_transaction_id` = :everysizeTransactionId AND `order_number_reported_at` IS NULL',
            [
                'now' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT),
                'everysizeTransactionId' => $everysizeTransactionId,
            ]
        );
    }

    /** The code reported to everysize: the first of the order's first delivery (the API takes one). */
    private static function trackingCode(OrderEntity $order): string
    {
        $trackingCodes = $order->getDeliveries()?->first()?->getTrackingCodes() ?? [];

        return trim((string) ($trackingCodes[0] ?? ''));
    }

    /**
     * Both plugins at once would import every order twice (see
     * Legacy\LegacyPlugin). Activating either is refused while the other is
     * active; this covers a state changed past Shopware. In the protocol once a
     * day, not every five minutes.
     */
    private function isLegacyPluginActive(): bool
    {
        if (!LegacyPlugin::isActive($this->connection)) {
            return false;
        }

        $this->logger->critical(self::LEGACY_PLUGIN_ACTIVE);

        $reported = $this->connection->fetchOne(
            'SELECT 1 FROM `everysize_checkout_protocol` WHERE `title` = :title AND `created_at` > (NOW(3) - INTERVAL 1 DAY)',
            ['title' => self::LEGACY_PLUGIN_ACTIVE]
        );
        if (!$reported) {
            $this->dataPersister->createProtocolEntry(
                self::LEGACY_PLUGIN_ACTIVE,
                'Both plugins together would import orders twice. Deactivate ITSEverysizeCheckout (do not uninstall it); this plugin carries on by itself.',
                false
            );
        }

        return true;
    }

    /**
     * Once per access data: order assignments lost with the previous plugin
     * are rebuilt from the checkout (MappingRecoveryService). A failure is
     * reported and retried on the next run; the import goes on regardless.
     */
    private function recoverAssignmentsOnce(SalesChannelEntity $salesChannel): void
    {
        if ($this->mappingRecovery->isDone()) {
            return;
        }

        $lines = [];
        try {
            $recovery = $this->mappingRecovery->recover(false, static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
            $this->mappingRecovery->markDone();
        } catch (\Throwable $e) {
            $this->logger->critical('Recovery of order assignments failed: '.$e->getMessage());
            $this->dataPersister->createProtocolEntry(
                sprintf('Recovery of order assignments from everysize failed (SalesChannel: %s)', $salesChannel->getName()),
                $e->getMessage(),
                false
            );

            return;
        }

        if ($recovery['recovered'] === 0 && $recovery['notFound'] === 0 && $recovery['mismatch'] === 0) {
            return;
        }

        $this->dataPersister->createProtocolEntry(
            sprintf(
                'Recovered %d order assignments from everysize (SalesChannel: %s)',
                $recovery['recovered'],
                $salesChannel->getName()
            ),
            sprintf(
                "%d orders checked, %d already assigned, %d not found in the shop, %d skipped\n%s",
                $recovery['checked'],
                $recovery['assigned'],
                $recovery['notFound'],
                $recovery['mismatch'],
                implode("\n", \array_slice($lines, 0, 100))
            ),
            true
        );
    }

    /**
     * Orders to report as shipped: in the completion state now, or — if they
     * left it before a run saw them — entered it within
     * TrackingCodeService::DAYS (state history of the order or a delivery).
     * Shipped and cancelled or returned within one interval: the shipping
     * report (with the tracking code) goes first, the cancellation or return
     * follows in the same run; before, the shipping was never reported.
     * Orders with a reported cancellation or return are left out: a shipping
     * report sets the order at everysize to COMPLETED again. A revoked order
     * at everysize is caught in transferOrderStatesToEverysize().
     *
     * @return list<array{id: string, everysize_order_id: string, everysize_transaction_id: string}>
     */
    private function getOrdersForShippingTransmission(string $state, string $salesChannelId): array
    {
        $sqlStatement = <<<'SQL'
            SELECT DISTINCT HEX(o.id) as id, HEX(eo.id) as everysize_order_id, eo.everysize_transaction_id
            FROM `order` o
            JOIN everysize_checkout_order eo ON (eo.order_id = o.id AND eo.order_version_id = o.version_id)
            LEFT JOIN order_delivery od ON (od.order_id = o.id AND od.order_version_id = o.version_id)
            WHERE o.version_id = UNHEX(:liveVersion)
            AND o.sales_channel_id = UNHEX(:salesChannelId)
            AND NOT EXISTS (
                SELECT 1 FROM everysize_checkout_order_line_item_status eolis
                WHERE eolis.everysize_order_id = eo.id AND eolis.status = :send
            )
            AND (
                o.state_id = UNHEX(:state) OR od.state_id = UNHEX(:state)
                OR (
                    o.order_date_time >= :orderedSince
                    AND NOT EXISTS (
                        SELECT 1 FROM everysize_checkout_order_line_item_status eolis
                        WHERE eolis.everysize_order_id = eo.id AND eolis.status IN (:cancel, :retour)
                    )
                    AND EXISTS (
                        SELECT 1 FROM state_machine_history h
                        WHERE h.to_state_id = UNHEX(:state)
                        AND h.referenced_version_id = o.version_id
                        AND h.created_at >= :shippedSince
                        AND (
                            (h.entity_name = 'order' AND h.referenced_id = o.id)
                            OR (h.entity_name = 'order_delivery' AND h.referenced_id = od.id)
                        )
                    )
                )
            )
            SQL;

        $now = time();

        return $this->connection->fetchAllAssociative($sqlStatement, [
            'state' => $state,
            'salesChannelId' => $salesChannelId,
            'send' => OrderExportService::LINE_ITEM_STATUS_SEND,
            'cancel' => OrderExportService::LINE_ITEM_STATUS_CANCEL,
            'retour' => OrderExportService::LINE_ITEM_STATUS_RETOUR,
            'liveVersion' => Defaults::LIVE_VERSION,
            // Shipped within DAYS means ordered within a few months — a cheap pre-filter.
            'orderedSince' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT, $now - 180 * 86400),
            'shippedSince' => gmdate(Defaults::STORAGE_DATE_TIME_FORMAT, $now - TrackingCodeService::DAYS * 86400),
        ]);
    }

    private function getOrdersForStateTransmission(string $state, string $salesChannelId, string $lineItemStatus): array
    {
        // One row per order, live version only. Shopware keeps edited orders as
        // additional versions (same id, other version_id); without the version
        // filter every version produced its own transmission. NOT EXISTS instead
        // of a LEFT JOIN on the status table, which multiplied the rows by the
        // number of status entries.
        $sqlStatement = <<<'SQL'
            SELECT DISTINCT HEX(o.id) as id, HEX(eo.id) as everysize_order_id, eo.everysize_transaction_id
            FROM `order` o
            JOIN everysize_checkout_order eo ON (eo.order_id = o.id AND eo.order_version_id = o.version_id)
            LEFT JOIN order_delivery od ON (od.order_id = o.id AND od.order_version_id = o.version_id)
            WHERE o.version_id = UNHEX(:liveVersion)
            AND o.sales_channel_id = UNHEX(:salesChannelId)
            AND (o.state_id = UNHEX(:state) OR od.state_id = UNHEX(:state))
            AND NOT EXISTS (
                SELECT 1 FROM everysize_checkout_order_line_item_status eolis
                WHERE eolis.everysize_order_id = eo.id AND eolis.status = :lineItemStatus
            )
            SQL;

        // fetchAllAssociative() with named parameters works with DBAL 3
        // (Shopware 6.6) and 4 (6.7); bindValue() with \PDO::PARAM_* does not
        // in DBAL 4.
        return $this->connection->fetchAllAssociative($sqlStatement, [
            'state' => $state,
            'salesChannelId' => $salesChannelId,
            'lineItemStatus' => $lineItemStatus,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);
    }
}
