<?php

declare(strict_types=1);

namespace Everysize\Checkout\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $orderState;
    private string $orderCompletionState;
    private string $orderCancellationState;
    private string $orderReturnedState;
    private string $paymentState;
    private string $deliveryState;
    private string $paypalPaymentType;
    private string $dispatchTypePostal;
    private string $fallbackLanguage;
    private string $carrier;
    private bool $isDebugMode = false;
    private bool $isSendMail = false;
    private string $defaultCustomerGroup;
    private bool $deactivateScheduledTask = false;
    private string $importSalesChannel = '';

    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function init(string $salesChannelId): void
    {
        $config = $this->getSalesChannelConfig($salesChannelId);

        $this
            ->setClientId(isset($config['clientId']) ? trim($config['clientId']) : '')
            ->setClientSecret(isset($config['clientSecret']) ? trim($config['clientSecret']) : '')
            ->setOrderState($config['orderState'] ?? '')
            ->setOrderCompletionState($config['orderComplete'] ?? '')
            ->setOrderCancellationState($config['orderCancel'] ?? '')
            ->setOrderReturnedState($config['orderReturn'] ?? '')
            ->setPaymentState($config['paymentState'] ?? '')
            ->setDeliveryState($config['deliveryState'] ?? '')
            ->setPaypalPaymentType($config['paypal'] ?? '')
            ->setDispatchTypePostal($config['dispatch'] ?? '')
            ->setFallbackLanguage($config['fallbackLanguage'] ?? '')
            ->setCarrier($config['carrier'] ?? '')
            ->setIsDebugMode($this->toBool($config['logging'] ?? false))
            ->setDefaultCustomerGroup($config['defaultCustomerGroup'] ?? '')
            ->setIsSendMail($this->toBool($config['sendMail'] ?? false))
            ->setDeactivateScheduledTask($this->toBool($config['deactivateScheduledTask'] ?? false))
            ->setImportSalesChannel($config['importSalesChannel'] ?? '')
        ;
    }

    /**
     * The admin stores true/false; values set elsewhere (system:config:set,
     * API) can arrive as "false"/"0", which (bool) would read as true.
     */
    private function toBool(mixed $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }

    private function getSalesChannelConfig(string $salesChannelId): array
    {
        return $this->systemConfigService->get('EverysizeCheckout.config', $salesChannelId);
    }

    /**
     * Reads a value of another sales channel without changing the state
     * set by init().
     */
    public function getValueOf(string $salesChannelId, string $key): string
    {
        return trim((string) $this->systemConfigService->get('EverysizeCheckout.config.'.$key, $salesChannelId));
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getOrderState(): string
    {
        return $this->orderState;
    }

    public function setOrderState(string $orderState): self
    {
        $this->orderState = $orderState;

        return $this;
    }

    public function getOrderCompletionState(): string
    {
        return $this->orderCompletionState;
    }

    public function setOrderCompletionState(string $orderCompletionState): self
    {
        $this->orderCompletionState = $orderCompletionState;

        return $this;
    }

    public function getOrderCancellationState(): string
    {
        return $this->orderCancellationState;
    }

    public function setOrderCancellationState(string $orderCancellationState): self
    {
        $this->orderCancellationState = $orderCancellationState;

        return $this;
    }

    public function getPaymentState(): string
    {
        return $this->paymentState;
    }

    public function setPaymentState(string $paymentState): self
    {
        $this->paymentState = $paymentState;

        return $this;
    }

    public function getPaypalPaymentType(): string
    {
        return $this->paypalPaymentType;
    }

    public function setPaypalPaymentType(string $paypalPaymentType): self
    {
        $this->paypalPaymentType = $paypalPaymentType;

        return $this;
    }

    public function getDispatchTypePostal(): string
    {
        return $this->dispatchTypePostal;
    }

    public function setDispatchTypePostal(string $dispatchTypePostal): self
    {
        $this->dispatchTypePostal = $dispatchTypePostal;

        return $this;
    }

    public function getCarrier(): string
    {
        return $this->carrier;
    }

    public function setCarrier(string $carrier): self
    {
        $this->carrier = $carrier;

        return $this;
    }

    public function isDebugMode(): bool
    {
        return $this->isDebugMode;
    }

    public function setIsDebugMode(bool $isDebugMode): self
    {
        $this->isDebugMode = $isDebugMode;

        return $this;
    }

    public function isSendMail(): bool
    {
        return $this->isSendMail;
    }

    public function setIsSendMail(bool $isSendMail): self
    {
        $this->isSendMail = $isSendMail;

        return $this;
    }

    public function getDefaultCustomerGroup(): string
    {
        return $this->defaultCustomerGroup;
    }

    public function setDefaultCustomerGroup(string $defaultCustomerGroup): self
    {
        $this->defaultCustomerGroup = $defaultCustomerGroup;

        return $this;
    }

    public function getDeliveryState(): string
    {
        return $this->deliveryState;
    }

    public function setDeliveryState(string $deliveryState): self
    {
        $this->deliveryState = $deliveryState;

        return $this;
    }

    public function getOrderReturnedState(): string
    {
        return $this->orderReturnedState;
    }

    public function setOrderReturnedState(string $orderReturnedState): self
    {
        $this->orderReturnedState = $orderReturnedState;

        return $this;
    }

    public function getFallbackLanguage(): ?string
    {
        return $this->fallbackLanguage;
    }

    public function setFallbackLanguage(?string $fallbackLanguage): self
    {
        $this->fallbackLanguage = $fallbackLanguage;

        return $this;
    }

    public function isDeactivateScheduledTask(): bool
    {
        return $this->deactivateScheduledTask;
    }

    public function setDeactivateScheduledTask(bool $deactivateScheduledTask): self
    {
        $this->deactivateScheduledTask = $deactivateScheduledTask;

        return $this;
    }

    public function getImportSalesChannel(): string
    {
        return $this->importSalesChannel;
    }

    public function setImportSalesChannel(?string $importSalesChannel): self
    {
        $this->importSalesChannel = trim((string) $importSalesChannel);

        return $this;
    }
}
