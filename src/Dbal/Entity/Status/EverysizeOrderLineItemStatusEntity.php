<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Status;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EverysizeOrderLineItemStatusEntity extends Entity
{
    use EntityIdTrait;
    protected string $everysizeOrderId;
    protected string $lineItemId;
    protected string $status;
    protected ?string $result = null;

    public function getEverysizeOrderId(): string
    {
        return $this->everysizeOrderId;
    }

    public function setEverysizeOrderId(string $everysizeOrderId): self
    {
        $this->everysizeOrderId = $everysizeOrderId;

        return $this;
    }

    public function getLineItemId(): string
    {
        return $this->lineItemId;
    }

    public function setLineItemId(string $lineItemId): self
    {
        $this->lineItemId = $lineItemId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): self
    {
        $this->result = $result;

        return $this;
    }
}
