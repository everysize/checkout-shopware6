<?php

declare(strict_types=1);

namespace Everysize\Checkout\Dbal\Entity\Protocol;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class EverysizeProtocolDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'everysize_checkout_protocol';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return EverysizeProtocolCollection::class;
    }

    public function getEntityClass(): string
    {
        return EverysizeProtocolEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('title', 'title'))->addFlags(new Required()),
            new LongTextField('message', 'message'),
            (new BoolField('state', 'state'))->addFlags(new Required()),
        ]);
    }
}
