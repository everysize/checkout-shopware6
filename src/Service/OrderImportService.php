<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Dbal\DataPersister;
use Everysize\Checkout\Dbal\DataProvider;
use Everysize\Checkout\Everysize\Amount;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupTranslation\PropertyGroupTranslationEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGenerator;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OrderImportService
{
    private LoggerInterface $logger;
    private ConfigProvider $configProvider;
    private EventDispatcherInterface $eventDispatcher;
    private DataProvider $dataProvider;
    private NumberRangeValueGenerator $valueGenerator;
    private Context $context;
    private DataPersister $dataPersister;

    private AbstractSalesChannelContextFactory $salesChannelContextFactory;
    private CartRuleLoader $ruleLoader;
    private Connection $connection;
    private CashRounding $rounding;
    private CashRoundingConfig $itemRounding;

    public function __construct(
        DataProvider $dataProvider,
        DataPersister $dataPersister,
        NumberRangeValueGenerator $valueGenerator,
        EventDispatcherInterface $eventDispatcher,
        CartRuleLoader $ruleLoader,
        LoggerInterface $logger,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        Connection $connection,
        CashRounding $rounding,
    ) {
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->dataProvider = $dataProvider;
        $this->valueGenerator = $valueGenerator;
        $this->dataPersister = $dataPersister;
        $this->context = Context::createCLIContext();
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->ruleLoader = $ruleLoader;
        $this->connection = $connection;
        $this->rounding = $rounding;
    }

    public function setConfigProvider(ConfigProvider $configProvider): void
    {
        $this->configProvider = $configProvider;
    }

    public function importOrder(array $orderItem, SalesChannelEntity $salesChannelEntity): string
    {
        // Before anything is written: an unparsable amount must not leave a
        // guest customer without an order behind.
        $orderItem = $this->normalizeAmounts($orderItem);

        foreach ($orderItem['lineItems'] as &$item) {
            if (!$product = $this->dataProvider->getProductByProductNumber($item['sku'])) {
                $error = sprintf('Could not find product for productNumber %s', $item['sku']);
                $this->logger->critical($error);
                throw new \Exception($error);
            }

            $item['product'] = $product;
        }
        unset($item);

        // Customer, order and everysize link in one transaction: if the order
        // cannot be written, the guest customer must not stay behind — every
        // retry (every run, per sales channel) created another one. The DAL
        // writes use the same connection and nest inside it.
        [$customer, $order] = $this->connection->transactional(function () use ($orderItem, $salesChannelEntity): array {
            $customer = $this->createGuestCustomer($orderItem, $salesChannelEntity);

            return [$customer, $this->createOrderMainData($orderItem, $customer, $salesChannelEntity)];
        });
        $orderEntity = $this->dataProvider->getOrderById($order['id']);

        $token = Uuid::randomHex();
        $salesChannelContext = $this->salesChannelContextFactory->create(
            $token,
            $salesChannelEntity->getId(),
            [
                SalesChannelContextService::LANGUAGE_ID => $salesChannelEntity->getLanguageId(),
                SalesChannelContextService::CUSTOMER_ID => $customer['id'],
                SalesChannelContextService::CUSTOMER_GROUP_ID => $this->configProvider->getDefaultCustomerGroup(),
            ]
        );

        $this->ruleLoader->loadByToken($salesChannelContext, $token);
        if ($this->configProvider->isSendMail() && $orderEntity !== null) {
            // The sales channel context works in 6.6 and 6.7; 6.7 no longer
            // accepts Context plus sales channel ID.
            // @phpstan-ignore method.deprecated (6.6 marks the constructor deprecated because of this very parameter type change)
            $orderPlacedEvent = new CheckoutOrderPlacedEvent($salesChannelContext, $orderEntity);

            $this->eventDispatcher->dispatch($orderPlacedEvent);
        }

        $eventName = 'state_enter.order_transaction.state.paid';
        $orderTransactionStateChangedEvent = new OrderStateMachineStateChangeEvent(
            $eventName,
            $orderEntity,
            $salesChannelContext->getContext()
        );

        $this->eventDispatcher->dispatch($orderTransactionStateChangedEvent, $eventName);

        return $order['orderNumber'];
    }

    /**
     * The API sends amounts as number_format() strings ("1,234.56"), see
     * Amount::parse(). Parsed once here, everything below calculates with floats.
     */
    private function normalizeAmounts(array $orderItem): array
    {
        foreach (['offersPrice', 'grossPrice', 'shippingCosts'] as $field) {
            if (isset($orderItem[$field])) {
                $orderItem[$field] = Amount::parse($orderItem[$field], $field);
            }
        }

        foreach ($orderItem['lineItems'] as $index => $item) {
            $orderItem['lineItems'][$index]['price'] = Amount::parse($item['price'] ?? null, sprintf('lineItems[%d].price', $index));
        }

        return $orderItem;
    }

    private function createOrderMainData(array $orderItem, array $customer, SalesChannelEntity $salesChannelEntity): array
    {
        $orderId = Uuid::randomHex();
        $orderAddress = $this->getAddressData($orderItem['billingAddress'], $orderId, $customer['id']);
        $country = $this->dataProvider->getCountryByIso($orderItem['billingAddress']['countryCode']);

        if (!$country) {
            $error = sprintf('Could not find country by iso-code %s', $orderItem['billingAddress']['countryCode']);
            $this->logger->critical($error);
            throw new \Exception($error);
        }

        if (!$salesChannelEntity->getCurrency()) {
            $error = sprintf('Could not get currency for sales-channel with id %s', $salesChannelEntity->getId());
            $this->logger->critical($error);
            throw new \Exception($error);
        }

        $addresses = [$orderAddress];
        $shippingAddress = null;
        if (json_encode($orderItem['billingAddress']) !== json_encode($orderItem['shippingAddress'])) {
            $shippingAddress = $this->getAddressData($orderItem['shippingAddress'], $orderId, $customer['id']);
            $addresses[] = $shippingAddress;
        }

        $currency = $this->dataProvider->getCurrencyByIso($orderItem['currency']);
        if ($currency === null) {
            throw new \Exception(sprintf('Could not find currency by iso-code %s', $orderItem['currency']));
        }
        $this->itemRounding = $currency->getItemRounding();

        // Shipping carries the tax rate of the items; an everysize order has
        // one item, with several the last one's.
        $taxRate = 0.0;
        foreach ($orderItem['lineItems'] as $lineItem) {
            $taxRate = $this->getProductTaxRate($lineItem['product'], $country);
        }

        $shippingCosts = $this->getShippingCosts($orderItem, $taxRate);
        $orderNumber = $this->valueGenerator->getValue('order', $this->context, $salesChannelEntity->getId(), false);

        $languageId = $salesChannelEntity->getLanguage()?->getId();
        if ($languageId === null) {
            $languageId = $this->configProvider->getFallbackLanguage();
        }

        $lineItems = $this->getOrderLineItems($orderItem, $languageId);
        $price = $this->getOrderPriceData($country, $lineItems, $shippingCosts);

        $order = [
            'id' => $orderId,
            'salesChannelId' => $salesChannelEntity->getId(),
            'deepLinkCode' => Random::getAlphanumericString(32),
            'languageId' => $languageId,
            'currencyId' => $salesChannelEntity->getCurrency()->getId(),
            'orderDateTime' => gmdate('Y-m-d H:i:s', strtotime($orderItem['created'])),
            'currencyFactor' => $salesChannelEntity->getCurrency()->getFactor(),
            'billingAddressId' => $orderAddress['id'],
            'price' => $price,
            'shippingCosts' => $shippingCosts,
            'orderNumber' => $orderNumber,
            'lineItems' => $lineItems,
            'addresses' => $addresses,
            'deliveries' => $this->getOrderDelivery(
                $orderId,
                $orderItem,
                $lineItems,
                $orderAddress,
                $shippingCosts,
                $shippingAddress
            ),
            'transactions' => $this->getOrderTransaction($orderId, $orderItem, $price),
            'stateId' => \in_array($orderItem['status'], ['PROCESSING', 'COMPLETED'])
                ? $this->configProvider->getOrderState()
                : $this->configProvider->getOrderCancellationState(),
            'orderCustomer' => $customer,
            'itemRounding' => $currency->getItemRounding()->jsonSerialize(),
            'totalRounding' => $currency->getTotalRounding()->jsonSerialize(),
        ];

        $this->dataPersister->createOrder($order);
        $this->dataPersister->createEverysizeTransactionId(
            $orderId,
            Uuid::fromHexToBytes($this->context->getVersionId()),
            $orderItem['everysizeOrderId'],
            self::getOriginalEmail($orderItem)
        );

        return $order;
    }

    /**
     * The customer's own address, sent by the checkout next to the forwarding
     * address (customer.originalEmail; missing from older checkout versions).
     * Kept at the everysize order for the merchant's information only — the
     * customer and the order keep the forwarding address, so every Shopware
     * mail and flow goes through everysize.
     */
    private static function getOriginalEmail(array $orderItem): ?string
    {
        $email = $orderItem['customer']['originalEmail'] ?? null;
        if (!\is_string($email) || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_substr($email, 0, 255);
    }

    private function getOrderLineItems(array $orderItem, string $languageId): array
    {
        $lineItems = [];
        $country = $this->dataProvider->getCountryByIso($orderItem['billingAddress']['countryCode']);
        foreach ($orderItem['lineItems'] as $item) {
            /* @var ProductEntity $product */
            $product = $item['product'];
            $orderLineItemId = Uuid::randomHex();

            $lineItems[] = [
                'id' => $orderLineItemId,
                'productId' => $product->getId(),
                'identifier' => $product->getId(),
                'referencedId' => $product->getId(),
                'price' => $this->getLineItemPriceData($item, $country),
                'priceDefinition' => $this->getLineItemPriceDefinition($item, $country),
                'unitPrice' => $item['price'],
                'totalPrice' => $this->round($item['price'] * $item['quantity']),
                'quantity' => $item['quantity'],
                'coverId' => $this->getProductCoverId($product),
                'label' => $this->getProductName($product, $item['title']),
                'type' => 'product',
                'payload' => [
                    'productNumber' => $product->getProductNumber(),
                    'options' => $this->getProductOptions($product, $languageId),
                ],
            ];
        }

        return $lineItems;
    }

    private function getProductOptions(ProductEntity $product, string $languageId): array
    {
        $result = [];

        if ($product->getOptions() && $product->getOptions()->count()) {
            /* @var PropertyGroupOptionEntity $option */
            $groupName = null;
            $groupValue = null;
            foreach ($product->getOptions()->getElements() as $option) {
                if ($option->getGroup()) {
                    /* @var PropertyGroupTranslationEntity $groupTranslation */
                    foreach ($option->getGroup()->getTranslations() as $groupTranslation) {
                        if ($groupTranslation->getLanguageId() === $languageId) {
                            $groupName = $groupTranslation->getName();
                            break;
                        }
                    }

                    foreach ($option->getTranslations() as $optionTranslation) {
                        if ($optionTranslation->getLanguageId() === $languageId) {
                            $groupValue = $optionTranslation->getName();
                            break;
                        }
                    }
                }

                if ($groupName && $groupValue) {
                    $result[] = [
                        'group' => $groupName,
                        'option' => $groupValue,
                    ];
                }
            }
        }

        return $result;
    }

    private function getProductCoverId(ProductEntity $product): ?string
    {
        $coverId = null;
        if ($product->getCover()) {
            $coverId = $product->getCover()->getMediaId();
        } elseif ($product->getParentId()) {
            if ($parentProduct = $this->dataProvider->getProductById($product->getParentId())) {
                if ($parentProduct->getCover()) {
                    $coverId = $parentProduct->getCover()->getMediaId();
                }
            }
        }

        return $coverId;
    }

    private function getProductName(ProductEntity $product, string $fallback): string
    {
        $productName = $fallback;
        if (!empty($product->getName())) {
            $productName = $product->getName();
        } elseif ($product->getParentId()) {
            if ($parentProduct = $this->dataProvider->getProductById($product->getParentId())) {
                if (!empty($parentProduct->getName())) {
                    $productName = $parentProduct->getName();
                }
            }
        }

        return $productName;
    }

    private function createGuestCustomer(array $orderItem, SalesChannelEntity $salesChannelEntity): array
    {
        $billingAddress = $this->getAddressData($orderItem['billingAddress'], 'billing');
        $shippingAddress = $this->getAddressData($orderItem['shippingAddress'], 'shipping');
        $customerId = Uuid::randomHex();
        $customerNumber = $this->valueGenerator->getValue('customer', $this->context, $salesChannelEntity->getId(), false);

        $customer = [
            'id' => $customerId,
            'salesChannelId' => $salesChannelEntity->getId(),
            'languageId' => $salesChannelEntity->getLanguage()->getId(),
            'email' => $orderItem['customer']['email'],
            'company' => $billingAddress['company'],
            'guest' => true,
            'groupId' => $this->configProvider->getDefaultCustomerGroup(),
            'customerNumber' => $customerNumber,
            'defaultBillingAddress' => $billingAddress,
            'defaultShippingAddress' => $shippingAddress,
            'defaultPaymentMethodId' => $this->configProvider->getPaypalPaymentType(),
            'salutationId' => $this->dataProvider->getSalutationUuidByKey('not_specified'),
            'firstName' => $billingAddress['firstName'],
            'lastName' => $billingAddress['lastName'],
        ];

        $this->dataPersister->createCustomer($customer);

        $customer['customerId'] = $customer['id'];

        return $customer;
    }

    private function getAddressData(
        array $address,
        ?string $orderId = null,
        ?string $customerId = null,
    ): array {
        $countryId = $this->dataProvider->getCountryByIso($address['countryCode'])->getId();

        $addressData = [
            'id' => Uuid::randomHex(),
            'company' => '',
            'firstName' => $address['firstName'],
            'lastName' => $address['lastName'],
            'street' => $address['addressLine1'],
            'zipcode' => $address['postalCode'],
            'city' => $address['city'],
            'countryId' => $countryId,
            'additionalAddressLine1' => $address['addressLine2'] ?? '',
            'salutationId' => $this->dataProvider->getSalutationUuidByKey('not_specified'),
        ];

        if ($orderId) {
            $addressData['orderId'] = $orderId;
        } else {
            $addressData['customerId'] = $customerId;
        }

        return $addressData;
    }

    private function getProductTaxRate(ProductEntity $product, CountryEntity $country): float
    {
        $tax = $product->getTax();
        if ($tax === null && $product->getParentId()) {
            if ($parentProduct = $this->dataProvider->getProductById($product->getParentId())) {
                $tax = $parentProduct->getTax();
            }
        }

        if (!$tax || $country->getCustomerTax()->getEnabled()) {
            return 0.0;
        }

        $productTaxRules = $tax->getRules();
        if ($productTaxRules !== null) {
            foreach ($productTaxRules as $taxRule) {
                if ($taxRule->getCountry() && $taxRule->getCountry()->getId() === $country->getId()) {
                    return $taxRule->getTaxRate();
                }
            }
        }

        return $tax->getTaxRate();
    }

    private function getDeliveryDates(array $lineItems, string $shippingMethodId): array
    {
        $shippingMethod = $this->dataProvider->getShippingMethod($shippingMethodId);
        $deliveryTime = DeliveryTime::createFromEntity($shippingMethod->getDeliveryTime());
        $deliveryDate = DeliveryDate::createFromDeliveryTime($deliveryTime);
        $earliest = $deliveryDate->getEarliest();
        $latest = $deliveryDate->getLatest();
        foreach ($lineItems as $lineItem) {
            if (!$lineItem['product']->getDeliveryTime()) {
                continue;
            }

            $deliveryTime = DeliveryDate::createFromDeliveryTime(DeliveryTime::createFromEntity($lineItem['product']->getDeliveryTime()));

            $earliest = max($earliest, $deliveryTime->getEarliest());
            $latest = max($latest, $deliveryTime->getLatest());
        }

        return [$earliest, $latest];
    }

    /**
     * Order total as Shopware's cart builds it for gross prices: the taxes of
     * the positions and the shipping costs, each already rounded, summed per
     * tax rate; net = gross - tax.
     */
    private function getOrderPriceData(CountryEntity $country, array $lineItems, array $shippingCosts): array
    {
        $positionPrice = 0.0;
        foreach ($lineItems as $lineItem) {
            $positionPrice += $lineItem['totalPrice'];
        }
        $positionPrice = $this->round($positionPrice);
        $total = $this->round($positionPrice + $shippingCosts['totalPrice']);

        $taxesByRate = [];
        foreach ([...array_column($lineItems, 'price'), $shippingCosts] as $price) {
            foreach ($price['calculatedTaxes'] as $calculatedTax) {
                $key = (string) $calculatedTax['taxRate'];
                $taxesByRate[$key] ??= ['tax' => 0.0, 'price' => 0.0, 'taxRate' => $calculatedTax['taxRate'], 'extensions' => []];
                $taxesByRate[$key]['tax'] = $this->round($taxesByRate[$key]['tax'] + $calculatedTax['tax']);
                $taxesByRate[$key]['price'] = $this->round($taxesByRate[$key]['price'] + $calculatedTax['price']);
            }
        }

        $tax = 0.0;
        $taxRules = [];
        foreach ($taxesByRate as $calculatedTax) {
            $tax += $calculatedTax['tax'];
            $taxRules[] = [
                'taxRate' => $calculatedTax['taxRate'],
                'extensions' => [],
                'percentage' => $total > 0 ? $calculatedTax['price'] / $total * 100 : 100,
            ];
        }

        return [
            'taxStatus' => $country->getCustomerTax()->getEnabled() ? 'net' : 'gross',
            'taxRules' => $taxRules,
            'rawTotal' => $total,
            'totalPrice' => $total,
            'positionPrice' => $positionPrice,
            'netPrice' => $this->round($total - $tax),
            'calculatedTaxes' => array_values($taxesByRate),
        ];
    }

    /**
     * Rounds like Shopware's own cart (CashRounding::mathRound(): commercial
     * rounding to the decimals of the currency). The gross amounts are what
     * the customer paid at everysize; rounding only removes float noise there.
     */
    private function round(float $value): float
    {
        return $this->rounding->mathRound($value, $this->itemRounding);
    }

    /**
     * Tax contained in a gross price, as Shopware calculates it: per unit,
     * multiplied by the quantity, then rounded (GrossPriceCalculator).
     */
    private function getGrossTax(float $unitPrice, float $taxRate, int $quantity = 1): float
    {
        return $this->round($unitPrice / ((100 + $taxRate) / 100) * ($taxRate / 100) * $quantity);
    }

    private function getLineItemPriceDefinition(array $item, CountryEntity $country): array
    {
        $itemTaxRate = $this->getProductTaxRate($item['product'], $country);

        return [
            'type' => 'quantity',
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'listPrice' => null,
            'isCalculated' => true,
            'referencePriceDefinition' => null,
            'taxRules' => [
                [
                    'taxRate' => $itemTaxRate,
                    'extensions' => [],
                    'percentage' => 100,
                ],
            ],
        ];
    }

    private function getLineItemPriceData(array $item, CountryEntity $country): array
    {
        $itemTaxRate = $this->getProductTaxRate($item['product'], $country);
        $totalPrice = $this->round($item['price'] * $item['quantity']);

        return [
            'quantity' => $item['quantity'],
            'listPrice' => null,
            'taxRules' => [
                [
                    'taxRate' => $itemTaxRate,
                    'extensions' => [],
                    'percentage' => 100,
                ],
            ],
            'unitPrice' => $item['price'],
            'totalPrice' => $totalPrice,
            'referencePrice' => null,
            'calculatedTaxes' => [
                [
                    'tax' => $this->getGrossTax($item['price'], $itemTaxRate, (int) $item['quantity']),
                    'price' => $totalPrice,
                    'taxRate' => $itemTaxRate,
                    'extensions' => [],
                ],
            ],
        ];
    }

    /**
     * The customer paid the order total at everysize — items plus shipping —
     * so the payment carries the total and taxes of the order. Without the
     * shipping costs the payment amount differed from the order total, and a
     * refund of the item price alone counted as a full refund
     * (EverysizeRefundApiController::applyPaymentState()).
     */
    private function getOrderTransaction(string $orderId, array $orderItem, array $orderPrice): array
    {
        return [
            [
                'orderId' => $orderId,
                'amount' => [
                    'unitPrice' => $orderPrice['totalPrice'],
                    'totalPrice' => $orderPrice['totalPrice'],
                    'quantity' => 1,
                    'taxRules' => $orderPrice['taxRules'],
                    'calculatedTaxes' => $orderPrice['calculatedTaxes'],
                ],
                'paymentMethodId' => $this->configProvider->getPaypalPaymentType(),
                'stateId' => $this->configProvider->getPaymentState(),
                'customFields' => $this->getTransactionCustomFields($orderItem),
            ],
        ];
    }

    private function getTransactionCustomFields(array $orderItem): array
    {
        if (isset($orderItem['payment']['paymentMethod'], $orderItem['payment']['resourceId']) && $orderItem['payment']['paymentMethod'] === 'PAYPAL') {
            return [
                'everysize_payment_method' => $orderItem['payment']['paymentMethod'],
                'everysize_paypal_capture_id' => $orderItem['payment']['resourceId'],
                'everysize_paypal_refund_ids' => [],
            ];
        }

        return [];
    }

    private function getOrderDelivery(string $orderId, array $orderItem, array $orderLineItems, array $orderAddress, array $shippingCosts, ?array $shippingAddress): array
    {
        [$earliest, $latest] = $this->getDeliveryDates($orderItem['lineItems'], $this->configProvider->getDispatchTypePostal());

        return [
            [
                'orderId' => $orderId,
                'stateId' => $this->configProvider->getDeliveryState(),
                'shippingOrderAddressId' => isset($shippingAddress)
                    ? $shippingAddress['id']
                    : $orderAddress['id'],
                'shippingMethodId' => $this->configProvider->getDispatchTypePostal(),
                'shippingDateEarliest' => $earliest,
                'shippingDateLatest' => $latest,
                'shippingCosts' => $shippingCosts,
                'positions' => $this->getOrderPositions($orderLineItems),
            ],
        ];
    }

    private function getOrderPositions(array $orderLineItems): array
    {
        $positions = [];
        foreach ($orderLineItems as $orderLineItem) {
            $orderLineItem['orderLineItemId'] = $orderLineItem['id'];
            unset($orderLineItem['id']);
            $positions[] = $orderLineItem;
        }

        return $positions;
    }

    private function getShippingCosts(array $orderItem, float $taxRate): array
    {
        return [
            'quantity' => 1,
            'taxRules' => [
                [
                    'taxRate' => $taxRate,
                    'extensions' => [],
                    'percentage' => 100,
                ],
            ],
            'listPrice' => null,
            'unitPrice' => $orderItem['shippingCosts'],
            'totalPrice' => $orderItem['shippingCosts'],
            'referencePrice' => null,
            'calculatedTaxes' => [
                [
                    'tax' => $this->getGrossTax($orderItem['shippingCosts'], $taxRate),
                    'price' => $orderItem['shippingCosts'],
                    'taxRate' => $taxRate,
                    'extensions' => [],
                ],
            ],
        ];
    }
}
