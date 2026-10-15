<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Checkout;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EverysizeOrderEntity>
 */
class EverysizeOrderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EverysizeOrderEntity::class;
    }
}
