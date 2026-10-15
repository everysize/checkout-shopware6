<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Everysize\Checkout\Everysize\ApiException;
use Everysize\Checkout\Everysize\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

class ClientService
{
    private const TOKEN_CACHE_KEY = 'everysize_checkout_token_%s';

    private Client $client;
    private ConfigProvider $configProvider;
    private array $accessTokens = [];
    private array $accessTokenExpires = [];
    private array $shopIds = [];
    private LoggerInterface $logger;
    private CacheItemPoolInterface $cache;

    public function __construct(Client $client, ConfigProvider $configProvider, LoggerInterface $logger, CacheItemPoolInterface $cache)
    {
        $this->client = $client;
        $this->configProvider = $configProvider;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function getAccessToken(?array $testConfig = null): bool
    {
        $clientId = $testConfig === null
            ? $this->configProvider->getClientId()
            : $testConfig['clientId'];
        // Tokens live in Shopware's object cache. Until 2.4 they were files in
        // the plugin directory, which fails on read-only or multi-server
        // installations.
        $cacheItem = $this->cache->getItem(sprintf(self::TOKEN_CACHE_KEY, sha1($this->getTokenKey($clientId))));
        $tokenData = $cacheItem->isHit() ? $cacheItem->get() : [];

        if ($testConfig === null && \is_array($tokenData) && \count($tokenData) === 3 && time() <= $tokenData[1]) {
            $this->accessTokens[$clientId] = $tokenData[0];
            $this->accessTokenExpires[$clientId] = (int) $tokenData[1];
            $this->shopIds[$clientId] = (int) $tokenData[2];
        } else {
            $auth = $testConfig === null
                ? base64_encode($this->configProvider->getClientId().':'.$this->configProvider->getClientSecret())
                : base64_encode($testConfig['clientId'].':'.$testConfig['clientSecret']);

            $response = $this->decodeResponse(
                $this->client->post($this->getRequestUrlScheme(Client::API_ENDPOINT_TOKEN), '', [
                    'Authorization: Basic '.$auth,
                ])
            );

            if ($response && isset($response['access_token'])) {
                $this->accessTokens[$clientId] = $response['access_token'];
                $this->accessTokenExpires[$clientId] = time() + $response['expires_in'];
                $this->shopIds[$clientId] = (int) $response['shop_id'];

                if ($testConfig === null) {
                    $cacheItem->set([
                        $this->accessTokens[$clientId],
                        $this->accessTokenExpires[$clientId],
                        $this->shopIds[$clientId],
                    ]);
                    $cacheItem->expiresAt((new \DateTimeImmutable())->setTimestamp($this->accessTokenExpires[$clientId]));
                    $this->cache->save($cacheItem);
                }
            } else {
                if ($testConfig === null) {
                    $this->logger->critical('Authentication failed!');
                    throw new \Exception('Authentication failed!');
                }

                return false;
            }
        }

        return true;
    }

    public function getClientAccessToken(): ?string
    {
        return $this->accessTokens[$this->configProvider->getClientId()] ?? null;
    }

    public function getClientShopId(): ?int
    {
        return $this->shopIds[$this->configProvider->getClientId()] ?? null;
    }

    public function getOrders(int $page = 0, int $pageSize = 100): array
    {
        try {
            if (!$this->checkAccessToken()) {
                return [];
            }

            $response = $this->decodeResponse(
                $this->client->get(sprintf(
                    $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDERS_NEW),
                    $this->getClientShopId(),
                    $page,
                    $pageSize
                ), ['Authorization: Bearer '.$this->getClientAccessToken()])
            );

            if (isset($response['content'])) {
                return $response['content'];
            }
        } catch (\Exception $e) {
            $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
            throw new \Exception($e->getMessage());
        }

        return [];
    }

    /**
     * One page of the orders the checkout already has a merchant order number
     * for, in every status (MappingRecoveryService). Throws if the page cannot
     * be read — a recovery must not count as done on half the orders.
     *
     * @return array{content: list<array<string, mixed>>, totalPages: int}
     */
    public function getAcknowledgedOrders(int $page, int $pageSize): array
    {
        if (!$this->checkAccessToken()) {
            throw new \Exception('No access token for the everysize API');
        }

        $response = $this->client->get(
            sprintf(
                $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDERS_ACKNOWLEDGED),
                $this->getClientShopId(),
                $page,
                $pageSize
            ),
            ['Authorization: Bearer '.$this->getClientAccessToken()]
        );

        $body = json_decode($response['body'], true);
        if ($response['status'] === 200 && \is_array($body) && isset($body['content']) && \is_array($body['content'])) {
            return [
                'content' => array_values($body['content']),
                'totalPages' => (int) ($body['totalPages'] ?? 1),
            ];
        }
        $this->logErrorIfExists('getAcknowledgedOrders', $response);

        throw new \Exception(sprintf('[%s] getAcknowledgedOrders: unexpected response (page %d)', $response['status'], $page));
    }

    /**
     * Current state of one order in the checkout. Throws if it cannot be read —
     * callers must not guess the state.
     */
    public function getOrder(string $everysizeOrderId): array
    {
        try {
            if (!$this->checkAccessToken()) {
                throw new \Exception('No access token for the everysize API');
            }

            $response = $this->client->get(
                sprintf(
                    $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER),
                    $this->getClientShopId(),
                    rawurlencode($everysizeOrderId)
                ),
                ['Authorization: Bearer '.$this->getClientAccessToken()]
            );

            $order = json_decode($response['body'], true);
            if ($response['status'] === 200 && is_array($order) && isset($order['status'])) {
                return $order;
            }
            $this->logErrorIfExists('getOrder', $response);

            throw new \Exception(sprintf('[%s] getOrder: unexpected response for order %s', $response['status'], $everysizeOrderId));
        } catch (\Exception $e) {
            $this->logger->critical(sprintf('[%s][%s] %s', $everysizeOrderId, $e->getCode(), $e->getMessage()));
            throw new \Exception($e->getMessage());
        }
    }

    public function sendOrderNumber(string $everysizeOrderId, string $orderNumber): bool
    {
        try {
            if (!$this->checkAccessToken()) {
                return false;
            }

            $response = $this->client->post(
                sprintf(
                    $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER_NUMBER),
                    $this->getClientShopId(),
                    $everysizeOrderId
                ),
                json_encode([
                    'merchantOrderNumber' => $orderNumber,
                ]),
                [
                    'Authorization: Bearer '.$this->getClientAccessToken(),
                    'Content-Type: application/json;charset=UTF-8',
                ]
            );

            if ($response['status'] === 204) {
                return true;
            }
            $this->logErrorIfExists('sendOrderNumber', $response);
        } catch (\Exception $e) {
            $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    public function revokeOrder(string $everysizeOrderId, OrderLineItemEntity $lineItem, string $reason): bool
    {
        try {
            if (!$this->checkAccessToken()) {
                return false;
            }

            $response = $this->client->post(
                sprintf(
                    $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER_REVOKE),
                    $this->getClientShopId(),
                    $everysizeOrderId
                ),
                json_encode([
                    'sku' => $lineItem->getProduct()->getProductNumber(),
                    'reason' => $reason,
                    'comment' => '',
                ]),
                [
                    'Authorization: Bearer '.$this->getClientAccessToken(),
                    'Content-Type: application/json;charset=UTF-8',
                ]
            );

            if ($response['status'] === 204) {
                return true;
            }
            $this->logErrorIfExists('revokeOrder', $response);
        } catch (\Exception $e) {
            $this->logger->critical(sprintf('[%s][%s] %s', $everysizeOrderId, $e->getCode(), $e->getMessage()));
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    public function fulfillOrder(string $everysizeOrderId, string $carrier, string $trackingCode): bool
    {
        try {
            if (!$this->checkAccessToken()) {
                return false;
            }

            $response = $this->client->post(
                sprintf(
                    $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER_FULFILLMENT),
                    $this->getClientShopId(),
                    $everysizeOrderId
                ),
                json_encode([
                    'carrier' => $carrier && !empty($trackingCode) ? $carrier : null,
                    'trackingCode' => $carrier && !empty($trackingCode) ? [$trackingCode] : null,
                ]),
                [
                    'Authorization: Bearer '.$this->getClientAccessToken(),
                    'Content-Type: application/json;charset=UTF-8',
                ]
            );

            if ($response['status'] === 201) {
                return true;
            }
            $this->logErrorIfExists('fulfillOrder', $response);
        } catch (\Exception $e) {
            $this->logger->critical(sprintf('[%s] %s', $e->getCode(), $e->getMessage()));
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    /**
     * Refunds the order's PayPal capture through everysize, on the merchant's
     * behalf (External API, same way as every other everysize plugin). The
     * idempotency key makes a repeated request (double click, timeout and
     * retry) refund only once.
     *
     * @param string|null $amount "10.00", null = everything not refunded yet
     *
     * @return array<string, mixed> refundId, status, amount, currency
     *
     * @throws ApiException everysize said no (reason in getReason())
     */
    public function refund(string $everysizeOrderId, ?string $amount, string $idempotencyKey): array
    {
        if (!$this->checkAccessToken()) {
            throw new \RuntimeException('No access token for the everysize API');
        }

        $response = $this->client->post(
            sprintf(
                $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER_REFUNDS),
                $this->getClientShopId(),
                rawurlencode($everysizeOrderId)
            ),
            json_encode($amount === null ? new \stdClass() : ['amount' => $amount]),
            [
                'Authorization: Bearer '.$this->getClientAccessToken(),
                'Idempotency-Key: '.$idempotencyKey,
            ]
        );

        $refund = json_decode($response['body'], true);
        if ($response['status'] === 201 && \is_array($refund)) {
            return $refund;
        }

        $exception = ApiException::fromResponse(sprintf('Refund of %s', $everysizeOrderId), $response);
        $this->logger->error(sprintf('[%s] %s %s %s', $everysizeOrderId, $exception->getMessage(), $exception->getReason(), $exception->getDetail()));

        throw $exception;
    }

    /**
     * All refunds of the order's PayPal capture, also those made in PayPal
     * directly; [] for an order not paid through everysize.
     *
     * @return list<array<string, mixed>> refundId, status, amount, currency, created
     *
     * @throws ApiException
     */
    public function getRefunds(string $everysizeOrderId): array
    {
        if (!$this->checkAccessToken()) {
            throw new \RuntimeException('No access token for the everysize API');
        }

        $response = $this->client->get(
            sprintf(
                $this->getRequestUrlScheme(Client::API_ENDPOINT_ORDER_REFUNDS),
                $this->getClientShopId(),
                rawurlencode($everysizeOrderId)
            ),
            ['Authorization: Bearer '.$this->getClientAccessToken()]
        );

        $refunds = json_decode($response['body'], true);
        if ($response['status'] === 200 && \is_array($refunds)) {
            return array_values(array_filter($refunds, '\is_array'));
        }

        throw ApiException::fromResponse(sprintf('Refunds of %s', $everysizeOrderId), $response);
    }

    private function checkAccessToken(): bool
    {
        if ($this->getClientAccessToken() === null || time() > ($this->accessTokenExpires[$this->configProvider->getClientId()] ?? 0)) {
            $this->getAccessToken();

            if ($this->getClientAccessToken() === null) {
                return false;
            }
        }

        return true;
    }

    private function decodeResponse(array $response): array
    {
        $response = json_decode($response['body'], true);

        if (isset($response['error']) && !empty($response['error'])) {
            $this->logger->critical(sprintf('[%s] %s', 500, $response['error_description']));
        }

        if (!is_array($response)) {
            return [];
        }

        return $response;
    }

    private function logErrorIfExists(string $title, array $response): void
    {
        if (isset($response['body'])) {
            $body = json_decode($response['body'], true);
            if (isset($body['error_description']) && !empty($body['error_description'])) {
                $this->logger->critical(sprintf('[%s] %s: %s', $response['status'], $title, $body['error_description']));
                throw new \Exception(sprintf('[%s] %s: %s', $response['status'], $title, $body['error_description']));
            } elseif (isset($body['title']) && !empty($body['title'])) {
                $this->logger->critical(sprintf('[%s] %s: %s', $response['status'], $title, $body['title']));
                throw new \Exception(sprintf('[%s] %s: %s', $response['status'], $title, $body['title']));
            }
        }
    }

    private function getRequestUrlScheme(string $endpoint): string
    {
        return $this->client->getApiUrl().$endpoint;
    }

    /**
     * Tokens belong to one API environment — a production token must never be
     * reused against a test environment or vice versa. Production keeps the
     * historic file name, so existing installations keep their cached token
     * after an update.
     */
    private function getTokenKey(string $clientId): string
    {
        if ($this->client->isProduction()) {
            return $clientId;
        }

        return parse_url($this->client->getApiUrl(), \PHP_URL_HOST).'-'.$clientId;
    }
}
