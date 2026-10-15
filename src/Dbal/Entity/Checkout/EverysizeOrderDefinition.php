<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Checkout;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class EverysizeOrderDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'everysize_checkout_order';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return EverysizeOrderCollection::class;
    }

    public function getEntityClass(): string
    {
        return EverysizeOrderEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('order_id', 'orderId'))->addFlags(new Required()),
            (new ReferenceVersionField(OrderDefinition::class, 'order_version_id'))->addFlags(new Required()),
            new StringField('everysize_transaction_id', 'everysizeTransactionId'),
            // The customer's own address (customer.originalEmail), for the merchant's information only.
            // Read by the administration from this entity — never copied to order.customFields, which
            // the administration saves back as a whole (then flows and exports would see it).
            new StringField('original_email', 'originalEmail'),
        ]);
    }
}
