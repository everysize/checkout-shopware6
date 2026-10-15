<?php

declare(strict_types=1);

namespace Everysize\Checkout\Legacy;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shown to the merchant in the administration when activating a plugin fails,
 * so the message says what to do — in German and English, the language of the
 * administration is not known here.
 */
class LegacyPluginException extends HttpException
{
    public const LEGACY_PLUGIN_ACTIVE = 'EVERYSIZE_CHECKOUT__LEGACY_PLUGIN_ACTIVE';
    public const SUCCESSOR_ACTIVE = 'EVERYSIZE_CHECKOUT__SUCCESSOR_ACTIVE';
    public const TAKEOVER_FAILED = 'EVERYSIZE_CHECKOUT__TAKEOVER_FAILED';

    public static function legacyPluginActive(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::LEGACY_PLUGIN_ACTIVE,
            'Das bisherige Plugin „everysize checkout“ (ITSEverysizeCheckout) ist noch aktiv. Bitte deaktiviere es zuerst – nicht deinstallieren – und aktiviere dann dieses Plugin. Einstellungen und Bestellzuordnungen werden dabei übernommen. / '
            .'The previous plugin "everysize checkout" (ITSEverysizeCheckout) is still active. Please deactivate it first – do not uninstall it – and then activate this plugin. Settings and order assignments are taken over.'
        );
    }

    public static function successorActive(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::SUCCESSOR_ACTIVE,
            '„everysize Checkout“ (EverysizeCheckout) ist aktiv und ersetzt dieses Plugin. Beide zusammen würden Bestellungen doppelt importieren – ITSEverysizeCheckout bleibt deshalb deaktiviert. / '
            .'"everysize Checkout" (EverysizeCheckout) is active and replaces this plugin. Both together would import orders twice – ITSEverysizeCheckout therefore stays deactivated.'
        );
    }

    public static function takeoverFailed(\Throwable $previous): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::TAKEOVER_FAILED,
            'Einstellungen und Bestellzuordnungen von ITSEverysizeCheckout ließen sich nicht übernehmen ({{ error }}). Das Plugin bleibt deaktiviert; die Daten des bisherigen Plugins sind unverändert. Bitte das bisherige Plugin nicht deinstallieren und den everysize-Support kontaktieren. / '
            .'Settings and order assignments of ITSEverysizeCheckout could not be taken over ({{ error }}). The plugin stays deactivated; the data of the previous plugin is unchanged. Please do not uninstall the previous plugin and contact everysize support.',
            ['error' => $previous->getMessage()],
            $previous
        );
    }
}
