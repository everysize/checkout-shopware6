<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

class DataPersister
{
    private Context $context;
    private EntityRepository $customerRepository;
    private EntityRepository $orderRepository;
    private EntityRepository $everysizeOrderRepository;
    private EntityRepository $everysizeOrderLineItemStatusRepository;
    private EntityRepository $everysizeProtocolRepository;

    public function __construct(
        EntityRepository $customerRepository,
        EntityRepository $orderRepository,
        EntityRepository $everysizeOrderRepository,
        EntityRepository $everysizeOrderLineItemStatusRepository,
        EntityRepository $everysizeProtocolRepository,
    ) {
        $this->context = Context::createCLIContext();
        $this->customerRepository = $customerRepository;
        $this->orderRepository = $orderRepository;
        $this->everysizeOrderRepository = $everysizeOrderRepository;
        $this->everysizeOrderLineItemStatusRepository = $everysizeOrderLineItemStatusRepository;
        $this->everysizeProtocolRepository = $everysizeProtocolRepository;
    }

    public function createCustomer(array $customer): void
    {
        // Shopware treats every customer created outside the Store API as a
        // registration and runs the "customer registered" flow — the guest got
        // a "Deine Registrierung bei …" mail via the everysize alias, even when
        // the import failed afterwards. The skip state reaches the register
        // event (SalesChannelContextRestorer copies the states), the flow
        // dispatcher then ignores it. Shopware 6.6 and 6.7.
        $context = Context::createCLIContext();
        $context->addState(Context::SKIP_TRIGGER_FLOW);

        $this->customerRepository->create([$customer], $context);
    }

    public function createOrder(array $order): void
    {
        $this->orderRepository->create([$order], $this->context);
    }

    public function createEverysizeTransactionId(string $orderId, string $orderVersionId, string $everysizeTrackingId, ?string $originalEmail = null): void
    {
        $this->everysizeOrderRepository->create([[
            'orderId' => $orderId,
            'orderVersionId' => Uuid::fromBytesToHex($orderVersionId),
            'everysizeTransactionId' => $everysizeTrackingId,
            'originalEmail' => $originalEmail,
        ]], $this->context);
    }

    public function createLineItemStatus(string $everysizeOrderId, string $lineItemId, string $status, ?string $result = null): void
    {
        $this->everysizeOrderLineItemStatusRepository->create([[
            'everysizeOrderId' => $everysizeOrderId,
            'lineItemId' => $lineItemId,
            'status' => $status,
            'result' => $result,
        ]], $this->context);
    }

    public function createProtocolEntry(string $title, string $message, bool $state): void
    {
        $this->everysizeProtocolRepository->create([[
            'title' => $title,
            'message' => $message,
            'state' => $state,
            'createdAt' => new \DateTime(),
        ]], $this->context);
    }
}
