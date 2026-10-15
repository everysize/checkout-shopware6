<?php

declare(strict_types=1);

namespace Everysize\Checkout\Subscriber;

use Everysize\Checkout\Dbal\DataProvider;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchResultLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderEventSubscriber implements EventSubscriberInterface
{
    private DataProvider $dataProvider;

    public function __construct(DataProvider $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_LOADED_EVENT => 'onOrderLoaded',
            OrderEvents::ORDER_SEARCH_RESULT_LOADED_EVENT => 'onOrderSearchResult',
        ];
    }

    public function onOrderSearchResult(EntitySearchResultLoadedEvent $event): void
    {
        $orders = $event->getResult()->getEntities();

        /**
         * @var OrderCollection $orders
         */
        foreach ($orders as $order) {
            $customFields = $order->getCustomFields();
            $customFields['everysize_id'] = $this->dataProvider->getEverysizeTransactionId($order->getId());
            $order->setCustomFields($customFields);
        }
    }

    public function onOrderLoaded(EntityLoadedEvent $event): void
    {
        /** @var list<OrderEntity> $orders */
        $orders = $event->getEntities();

        foreach ($orders as $order) {
            $customFields = $order->getCustomFields();
            $customFields['everysize_id'] = $this->dataProvider->getEverysizeTransactionId($order->getId());
            $order->setCustomFields($customFields);
        }
    }
}
