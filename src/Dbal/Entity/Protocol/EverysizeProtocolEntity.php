<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Protocol;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EverysizeProtocolEntity extends Entity
{
    use EntityIdTrait;
    protected string $title;
    protected ?string $message = null;
    protected bool $state;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): ?self
    {
        $this->message = $message;

        return $this;
    }

    public function getState(): bool
    {
        return $this->state;
    }

    public function setState(bool $state): self
    {
        $this->state = $state;

        return $this;
    }
}
