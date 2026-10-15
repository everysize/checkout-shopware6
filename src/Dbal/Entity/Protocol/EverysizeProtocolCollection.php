<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Protocol;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EverysizeProtocolEntity>
 */
class EverysizeProtocolCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EverysizeProtocolEntity::class;
    }
}
