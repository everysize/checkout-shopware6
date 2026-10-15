<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Api;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Everysize\ApiException;
use Everysize\Checkout\Service\ClientService;
use Everysize\Checkout\Service\ConfigProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Refund card of the "everysize" tab. Refunds go to everysize (External API),
 * which refunds the PayPal capture on the merchant's behalf with its partner
 * access — the same way as every other everysize plugin, so everysize knows
 * each refund at once. Until 3.0 the plugin refunded through the merchant's
 * PayPal plugin (SwagPayPal) instead, and everysize learned of it only in its
 * nightly sync, and only for revoked orders.
 *
 * The list comes from everysize, which reads it from the PayPal order: it
 * also shows refunds made in PayPal directly or through the PayPal plugin
 * before. "Refunded" counts COMPLETED and PENDING refunds, like the other
 * plugins. The access data are those of the order's sales channel, as for the
 * status export.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class EverysizeRefundApiController extends AbstractController
{
    private const REFUNDED_STATES = ['COMPLETED', 'PENDING'];

    /** "10.00": the API format of an amount. */
    private const AMOUNT_PATTERN = '/^\d{1,7}(\.\d{1,2})?$/';

    /** One key per refund the user starts; the administration makes it. */
    private const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    private ClientService $clientService;
    private ConfigProvider $configProvider;
    private Connection $connection;
    private EntityRepository $orderRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    private LoggerInterface $logger;

    public function __construct(
        ClientService $clientService,
        ConfigProvider $configProvider,
        Connection $connection,
        EntityRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger,
    ) {
        $this->clientService = $clientService;
        $this->configProvider = $configProvider;
        $this->connection = $connection;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/everysize/order/{orderId}/refunds', name: 'api.action.everysize.order.refunds', defaults: ['_acl' => ['order.viewer']], methods: ['GET'])]
    public function refunds(string $orderId, Context $context): JsonResponse
    {
        [$order, $transaction, $everysizeOrderId] = $this->loadOrder($orderId, $context);
        if ($order === null || $transaction === null || $everysizeOrderId === null) {
            return self::error(Response::HTTP_NOT_FOUND, 'NOT_AN_EVERYSIZE_ORDER', 'Not an everysize order');
        }

        try {
            $this->useAccessDataOf($order);

            return new JsonResponse($this->summary($order, $transaction, $this->clientService->getRefunds($everysizeOrderId)));
        } catch (ApiException $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, $e->getReason(), $e->getMessage(), $e->getDetail());
        } catch (\Throwable $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, '', $e->getMessage());
        }
    }

    /**
     * Body: {"amount": "10.00", "idempotencyKey": "..."}. Afterwards the
     * payment state follows all refunds together: refunded from the paid
     * amount on, partially refunded below.
     */
    #[Route(path: '/api/_action/everysize/order/{orderId}/refunds', name: 'api.action.everysize.order.refund', defaults: ['_acl' => ['order.editor']], methods: ['POST'])]
    public function refund(string $orderId, Request $request, Context $context): JsonResponse
    {
        [$order, $transaction, $everysizeOrderId] = $this->loadOrder($orderId, $context);
        if ($order === null || $transaction === null || $everysizeOrderId === null) {
            return self::error(Response::HTTP_NOT_FOUND, 'NOT_AN_EVERYSIZE_ORDER', 'Not an everysize order');
        }

        $amount = self::normalizeAmount($request->request->all()['amount'] ?? null);
        $key = (string) $request->request->get('idempotencyKey', '');
        if ($amount === null) {
            return self::error(Response::HTTP_BAD_REQUEST, 'REFUND_AMOUNT_INVALID', 'Invalid amount');
        }
        if (preg_match(self::IDEMPOTENCY_PATTERN, $key) !== 1) {
            return self::error(Response::HTTP_BAD_REQUEST, 'IDEMPOTENCY_KEY_REQUIRED', 'Invalid idempotency key');
        }

        try {
            $this->useAccessDataOf($order);
            $refund = $this->clientService->refund($everysizeOrderId, $amount, $key);
        } catch (ApiException $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, $e->getReason(), $e->getMessage(), $e->getDetail());
        } catch (\Throwable $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, '', $e->getMessage());
        }

        // The refund is done. A list or state that cannot be updated now is no
        // reason to report it as failed: the next view reads the list again.
        $summary = null;
        try {
            $summary = $this->summary($order, $transaction, $this->clientService->getRefunds($everysizeOrderId));
            $this->applyPaymentState($transaction, $summary['refunded'], $summary['paid'], $context);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('[%s] Refund %s done, but refunds or payment state not updated: %s', $everysizeOrderId, (string) ($refund['refundId'] ?? ''), $e->getMessage()));
        }

        return new JsonResponse(['refund' => $refund, 'summary' => $summary]);
    }

    /**
     * @return array{0: OrderEntity|null, 1: OrderTransactionEntity|null, 2: string|null}
     */
    private function loadOrder(string $orderId, Context $context): array
    {
        if (!Uuid::isValid($orderId)) {
            return [null, null, null];
        }

        $everysizeOrderId = $this->connection->fetchOne(
            'SELECT `everysize_transaction_id` FROM `everysize_checkout_order`
             WHERE `order_id` = UNHEX(:orderId) AND `order_version_id` = UNHEX(:liveVersion)
             LIMIT 1',
            ['orderId' => $orderId, 'liveVersion' => Defaults::LIVE_VERSION]
        );

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return [
            $order,
            $order?->getTransactions()?->first(),
            \is_string($everysizeOrderId) && $everysizeOrderId !== '' ? $everysizeOrderId : null,
        ];
    }

    private function useAccessDataOf(OrderEntity $order): void
    {
        $this->configProvider->init($order->getSalesChannelId());
        if ($this->configProvider->getClientId() === '') {
            throw new \RuntimeException('No everysize access data for the sales channel of this order');
        }
    }

    /**
     * @param list<array<string, mixed>> $refunds
     *
     * @return array{paid: float, refunded: float, open: float, currency: string, refunds: list<array<string, mixed>>}
     */
    private function summary(OrderEntity $order, OrderTransactionEntity $transaction, array $refunds): array
    {
        $paid = round($transaction->getAmount()->getTotalPrice(), 2);
        $refunded = 0.0;
        foreach ($refunds as $refund) {
            if (\in_array(strtoupper((string) ($refund['status'] ?? '')), self::REFUNDED_STATES, true)) {
                $refunded += (float) ($refund['amount'] ?? 0);
            }
        }
        $refunded = round($refunded, 2);

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'open' => max(0.0, round($paid - $refunded, 2)),
            'currency' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
            'refunds' => $refunds,
        ];
    }

    /**
     * Only transitions Shopware has: paid (or partially paid, cancelled) to
     * partially refunded or refunded, partially refunded to refunded. A
     * second partial refund leaves "partially refunded" as it is.
     */
    private function applyPaymentState(OrderTransactionEntity $transaction, float $refunded, float $paid, Context $context): void
    {
        if ($refunded <= 0) {
            return;
        }

        $state = $transaction->getStateMachineState()?->getTechnicalName();
        if ($state === OrderTransactionStates::STATE_REFUNDED) {
            return;
        }

        if (round($refunded * 100) >= round($paid * 100)) {
            $this->transactionStateHandler->refund($transaction->getId(), $context);

            return;
        }

        if ($state !== OrderTransactionStates::STATE_PARTIALLY_REFUNDED) {
            $this->transactionStateHandler->refundPartially($transaction->getId(), $context);
        }
    }

    /**
     * The administration sends a number; "10", "10.5" and "10.50" become
     * "10.50"-style strings. Anything else, zero or less is refused.
     */
    private static function normalizeAmount(mixed $amount): ?string
    {
        if (\is_int($amount) || \is_float($amount)) {
            $amount = number_format(round((float) $amount, 2), 2, '.', '');
        }
        if (!\is_string($amount) || preg_match(self::AMOUNT_PATTERN, $amount) !== 1 || (float) $amount <= 0) {
            return null;
        }

        return $amount;
    }

    /** Shopware's error format, so the administration shows code and detail. */
    private static function error(int $status, string $code, string $title, string $detail = ''): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code' => $code,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status);
    }
}
