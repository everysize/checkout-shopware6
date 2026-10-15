<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Everysize\Checkout\Dbal\DataPersister;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

class OrderExportService
{
    public const LINE_ITEM_STATUS_SEND = 'send';
    public const LINE_ITEM_STATUS_CANCEL = 'cancel';
    public const LINE_ITEM_STATUS_RETOUR = 'retour';

    /** Result of a status (column result, empty = reported): not transmitted, the order is revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Result of a status: known at everysize already (assignment recovered from everysize). */
    public const RESULT_PRESENT = 'present';

    private DataPersister $dataPersister;

    public function __construct(DataPersister $dataPersister)
    {
        $this->dataPersister = $dataPersister;
    }

    public function storeLineItemFulfillment(string $everysizeOrderId, ?OrderLineItemEntity $lineItems, ?string $result = null): void
    {
        $this->storeLineItemStatus($everysizeOrderId, $lineItems, self::LINE_ITEM_STATUS_SEND, $result);
    }

    public function storeLineItemCancellation(string $everysizeOrderId, ?OrderLineItemEntity $lineItem): void
    {
        $this->storeLineItemStatus($everysizeOrderId, $lineItem, self::LINE_ITEM_STATUS_CANCEL);
    }

    public function storeLineItemReturnal(string $everysizeOrderId, ?OrderLineItemEntity $lineItem): void
    {
        $this->storeLineItemStatus($everysizeOrderId, $lineItem, self::LINE_ITEM_STATUS_RETOUR);
    }

    private function storeLineItemStatus(string $everysizeOrderId, ?OrderLineItemEntity $lineItem, string $status, ?string $result = null): void
    {
        $this->dataPersister->createLineItemStatus($everysizeOrderId, $lineItem->getId(), $status, $result);
    }
}
