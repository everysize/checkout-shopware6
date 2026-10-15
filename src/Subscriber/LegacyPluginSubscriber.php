<?php

declare(strict_types=1);

namespace Everysize\Checkout\Subscriber;

use Everysize\Checkout\Legacy\LegacyPlugin;
use Everysize\Checkout\Legacy\LegacyPluginException;
use Shopware\Core\Framework\Plugin\Event\PluginPreActivateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses to activate the previous plugin ITSEverysizeCheckout while this
 * plugin is active (the subscriber only exists then). Shopware dispatches the
 * event before it changes anything, so the exception leaves both plugins as
 * they were. See Legacy\LegacyPlugin.
 */
class LegacyPluginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PluginPreActivateEvent::class => 'onPluginPreActivate',
        ];
    }

    public function onPluginPreActivate(PluginPreActivateEvent $event): void
    {
        if ($event->getPlugin()->getName() === LegacyPlugin::TECHNICAL_NAME) {
            throw LegacyPluginException::successorActive();
        }
    }
}
