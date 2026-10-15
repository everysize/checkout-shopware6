<?php

declare(strict_types=1);

namespace Everysize\Checkout\Everysize;

use Composer\InstalledVersions;
use Everysize\Checkout\Dbal\DataProvider;

class Client
{
    /** Production checkout API — used whenever nothing else is set explicitly. */
    public const API_URL = 'https://ext-checkout.everysize.com';
    public const API_URL_ENV = 'EVERYSIZE_CHECKOUT_API_URL';

    /** Values accepted in EVERYSIZE_CHECKOUT_API_URL: https on a host under everysize.com, no port, no path. */
    private const API_URL_PATTERN = '~^https://([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+everysize\.com$~';

    public const API_ENDPOINT_TOKEN = '/api/v1/oauth/token';
    public const API_ENDPOINT_ORDERS_NEW = '/api/v1/shops/%s/orders?status=PROCESSING,REVOKING,REVOKED&acknowledged=false&pageNumber=%s&pageSize=%s';
    public const API_ENDPOINT_ORDERS_ACKNOWLEDGED = '/api/v1/shops/%s/orders?acknowledged=true&pageNumber=%s&pageSize=%s';
    public const API_ENDPOINT_ORDER = '/api/v1/shops/%s/orders/%s';
    public const API_ENDPOINT_ORDER_FULFILLMENT = '/api/v1/shops/%s/orders/%s/fulfillment';
    public const API_ENDPOINT_ORDER_REVOKE = '/api/v1/shops/%s/orders/%s/revocations';
    public const API_ENDPOINT_ORDER_NUMBER = '/api/v1/shops/%s/orders/%s/merchant-order-number';
    public const API_ENDPOINT_ORDER_REFUNDS = '/api/v1/shops/%s/orders/%s/refunds';

    private DataProvider $dataProvider;
    private ?string $apiUrlOverride;

    public function __construct(DataProvider $dataProvider, ?string $apiUrlOverride = null)
    {
        $this->dataProvider = $dataProvider;
        $this->apiUrlOverride = $apiUrlOverride;
    }

    /**
     * Base URL of the everysize checkout API.
     *
     * Without EVERYSIZE_CHECKOUT_API_URL this is always production, so every
     * merchant installation talks to the live checkout without any setup.
     * A test or development shop sets the address of an everysize test
     * environment there; everysize hands it out together with the test
     * credentials.
     *
     * A value that is set must be https on a host under everysize.com,
     * without port or path. Anything else is an error and does NOT fall back
     * to production: a test shop with a typo must never send its orders to
     * the live checkout.
     */
    public function getApiUrl(): string
    {
        return self::resolveApiUrl($this->apiUrlOverride);
    }

    public static function resolveApiUrl(?string $configured): string
    {
        $value = strtolower(rtrim(trim((string) $configured), '/'));
        if ($value === '') {
            return self::API_URL;
        }

        if (!preg_match(self::API_URL_PATTERN, $value)) {
            throw new \RuntimeException(sprintf('%s=%s is not allowed: expected https://<host>.everysize.com without port or path', self::API_URL_ENV, $value));
        }

        return $value;
    }

    public function isProduction(): bool
    {
        return $this->getApiUrl() === self::API_URL;
    }

    public function get(string $url, array $additionalHeaders = []): array
    {
        return $this->_exec('GET', $url, null, $additionalHeaders);
    }

    public function put(string $url, ?string $data = null, array $additionalHeaders = []): array
    {
        return $this->_exec('PUT', $url, $data, $additionalHeaders);
    }

    public function post(string $url, ?string $data = null, array $additionalHeaders = []): array
    {
        return $this->_exec('POST', $url, $data, $additionalHeaders);
    }

    protected function _exec(string $type, string $url, ?string $data, array $additionalHeaders): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $this->getHeader($type, $data, $additionalHeaders),
        ]);

        switch ($type) {
            case 'GET':
                curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'GET');
                break;
            case 'POST':
                curl_setopt($ch, \CURLOPT_POST, true);
                curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, \CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($ch, \CURLOPT_POST, true);
                curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, \CURLOPT_POSTFIELDS, $data);
                break;
        }

        $response = curl_exec($ch);
        if ($response === false) {
            // Network/TLS failure: report it instead of crashing with a
            // TypeError in substr() below.
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException(sprintf('everysize API not reachable (%s %s): %s', $type, $url, $error));
        }
        $status = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
        $content_type = curl_getinfo($ch, \CURLINFO_CONTENT_TYPE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($ch);

        return [
            'status' => $status,
            'header_size' => $header_size,
            'content_type' => $content_type,
            'header' => $header,
            'body' => $body,
        ];
    }

    private function getHeader(string $type, ?string $data, array $additionalHeaders): array
    {
        $shopwareVersion = class_exists(InstalledVersions::class)
            ? InstalledVersions::getVersion('shopware/core')
            : 'N/A';

        $shopwareVersion = explode('@', $shopwareVersion ?? '')[0];
        $pluginVersion = $this->dataProvider->getPluginVersion();

        $header = [
            'ERP-Shop-System: Shopware',
            'ERP-Shop-System-Version: '.$shopwareVersion,
            'Integration-Partner: everysize.com',
            'Interface-Version: '.$pluginVersion,
            'User-Agent: everysizeCheckoutPlugin',
        ];
        $postHeader = [
            'Content-Type: application/json',
            'Content-Length: '.($data === null ? 0 : \strlen($data)),
        ];

        if (in_array($type, ['POST', 'PUT'])) {
            $header = array_merge($header, $postHeader);
        }

        return array_merge($header, $additionalHeaders);
    }
}
