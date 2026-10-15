<?php

declare(strict_types=1);

namespace Everysize\Checkout\Task;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class LogCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'everysize_checkout.log_cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
