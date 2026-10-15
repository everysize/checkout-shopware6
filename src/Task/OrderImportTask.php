<?php

declare(strict_types=1);

namespace Everysize\Checkout\Task;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class OrderImportTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'everysize_checkout.order_import';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
