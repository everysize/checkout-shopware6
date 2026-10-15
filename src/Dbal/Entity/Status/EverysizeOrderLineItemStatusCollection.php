<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Status;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EverysizeOrderLineItemStatusEntity>
 */
class EverysizeOrderLineItemStatusCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EverysizeOrderLineItemStatusEntity::class;
    }
}
