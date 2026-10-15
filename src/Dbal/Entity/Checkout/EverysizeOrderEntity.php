<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Checkout;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EverysizeOrderEntity extends Entity
{
    use EntityIdTrait;
    protected string $orderId;
    protected string $orderVersionId;
    protected string $everysizeTransactionId;
    protected ?string $originalEmail = null;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): self
    {
        $this->orderVersionId = $orderVersionId;

        return $this;
    }

    public function getEverysizeTransactionId(): string
    {
        return $this->everysizeTransactionId;
    }

    public function setEverysizeTransactionId(string $transactionId): self
    {
        $this->everysizeTransactionId = $transactionId;

        return $this;
    }

    public function getOriginalEmail(): ?string
    {
        return $this->originalEmail;
    }

    public function setOriginalEmail(?string $originalEmail): self
    {
        $this->originalEmail = $originalEmail;

        return $this;
    }
}
