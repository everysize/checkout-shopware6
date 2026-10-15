<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal;

use Everysize\Checkout\Dbal\Entity\Checkout\EverysizeOrderCollection;
use Everysize\Checkout\EverysizeCheckout;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\Salutation\SalutationCollection;

class DataProvider
{
    /** @var EntityRepository<CountryCollection> */
    private EntityRepository $countryRepository;
    /** @var EntityRepository<ProductCollection> */
    private EntityRepository $productRepository;
    /** @var EntityRepository<ShippingMethodCollection> */
    private EntityRepository $shippingMethodRepository;
    /** @var EntityRepository<SalesChannelCollection> */
    private EntityRepository $salesChannelRepository;
    /** @var EntityRepository<EverysizeOrderCollection> */
    private EntityRepository $everysizeOrderRepository;
    /** @var EntityRepository<OrderCollection> */
    private EntityRepository $orderRepository;
    /** @var EntityRepository<PluginCollection> */
    private EntityRepository $pluginRepository;
    /** @var EntityRepository<CurrencyCollection> */
    private EntityRepository $currencyRepository;
    /** @var EntityRepository<SalutationCollection> */
    private EntityRepository $salutationRepository;

    /**
     * @param EntityRepository<CountryCollection>        $countryRepository
     * @param EntityRepository<ProductCollection>        $productRepository
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     * @param EntityRepository<SalesChannelCollection>   $salesChannelRepository
     * @param EntityRepository<EverysizeOrderCollection> $everysizeOrderRepository
     * @param EntityRepository<OrderCollection>          $orderRepository
     * @param EntityRepository<PluginCollection>         $pluginRepository
     * @param EntityRepository<CurrencyCollection>       $currencyRepository
     * @param EntityRepository<SalutationCollection>     $salutationRepository
     */
    public function __construct(
        EntityRepository $countryRepository,
        EntityRepository $productRepository,
        EntityRepository $shippingMethodRepository,
        EntityRepository $salesChannelRepository,
        EntityRepository $everysizeOrderRepository,
        EntityRepository $orderRepository,
        EntityRepository $pluginRepository,
        EntityRepository $currencyRepository,
        EntityRepository $salutationRepository,
    ) {
        $this->countryRepository = $countryRepository;
        $this->productRepository = $productRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->everysizeOrderRepository = $everysizeOrderRepository;
        $this->orderRepository = $orderRepository;
        $this->pluginRepository = $pluginRepository;
        $this->currencyRepository = $currencyRepository;
        $this->salutationRepository = $salutationRepository;
    }

    public function getCurrencyByIso(string $isoCode): ?CurrencyEntity
    {
        return $this->currencyRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('isoCode', $isoCode)),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getCountryByIso(string $isoCode): ?CountryEntity
    {
        return $this->countryRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('iso', $isoCode)),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getSalutationUuidByKey(string $salutationKey): string
    {
        $salutation = $this->salutationRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('salutationKey', $salutationKey)),
            Context::createCLIContext()
        )->getEntities()->first();

        if ($salutation === null) {
            throw new \RuntimeException(sprintf('Salutation "%s" not found', $salutationKey));
        }

        return $salutation->getId();
    }

    public function getOrderById(string $orderId): ?OrderEntity
    {
        return $this->orderRepository->search(
            (new Criteria([$orderId]))->addAssociations([
                'currency',
                'orderCustomer',
                'orderCustomer.salutation',
                'addresses',
                'addresses.country',
                'deliveries',
                'deliveries.shippingMethod',
                'deliveries.shippingOrderAddress',
                'deliveries.shippingOrderAddress.country',
                'lineItems',
                'transactions',
                'transactions.paymentMethod',
                'lineItems.cover',
                'lineItems.product',
            ]),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getProductByProductNumber(string $productNumber): ?ProductEntity
    {
        return $this->productRepository->search(
            (new Criteria())
                ->addFilter(new EqualsFilter('productNumber', $productNumber))
                ->addAssociations([
                    'tax.rules',
                    'tax.rules.country',
                    'translations',
                    'options',
                    'options.translations',
                    'options.group',
                    'options.group.translations',
                ]),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getProductById(string $productId): ?ProductEntity
    {
        return $this->productRepository->search(
            (new Criteria([$productId]))
                ->addAssociations([
                    'tax.rules',
                    'tax.rules.country',
                    'translations',
                ]),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getShippingMethod(string $shippingMethodId): ?ShippingMethodEntity
    {
        // Shopware 6.7 no longer autoloads the delivery time (6.6 did).
        return $this->shippingMethodRepository->search(
            (new Criteria([$shippingMethodId]))->addAssociation('deliveryTime'),
            Context::createCLIContext()
        )->getEntities()->first();
    }

    public function getSalesChannels(): SalesChannelCollection
    {
        return $this->salesChannelRepository->search(
            (new Criteria())
                ->addAssociation('language')
                ->addAssociation('currency'),
            Context::createCLIContext()
        )->getEntities();
    }

    public function isEverysizeOrder(string $everysizeTransactionId): bool
    {
        return $this->everysizeOrderRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('everysizeTransactionId', $everysizeTransactionId)),
            Context::createCLIContext()
        )->getTotal() > 0;
    }

    public function getOrderNumberByEverysizeTransactionId(string $everysizeTransactionId): ?string
    {
        if ($ezOrder = $this->everysizeOrderRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('everysizeTransactionId', $everysizeTransactionId)),
            Context::createCLIContext()
        )->getEntities()->first()) {
            if ($order = $this->getOrderById($ezOrder->getOrderId())) {
                return $order->getOrderNumber();
            }
        }

        return null;
    }

    public function getEverysizeTransactionId(string $orderId): ?string
    {
        $result = $this
            ->everysizeOrderRepository
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('orderId', $orderId)),
                Context::createCLIContext()
            )
        ;

        return $result->getEntities()->first()?->getEverysizeTransactionId() ?? '';
    }

    public function getPluginVersion(): string
    {
        $result = $this->pluginRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('name', EverysizeCheckout::TECHNICAL_NAME)),
            Context::createCLIContext()
        );

        return $result->getEntities()->first()?->getVersion() ?? '';
    }
}
