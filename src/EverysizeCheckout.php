<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Doctrine\DBAL\Connection;
use Everysize\Checkout\Legacy\LegacyPlugin;
use Everysize\Checkout\Legacy\LegacyPluginException;
use Everysize\Checkout\Legacy\LegacyTakeover;
use Everysize\Checkout\Service\UninstallService;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Successor of the previous Store plugin ITSEverysizeCheckout (up to 2.5.1).
 * Shopware sees it as a different plugin; the takeover of settings and order
 * assignments is described in Legacy\LegacyTakeover, the rule "never both
 * active" in Legacy\LegacyPlugin.
 */
class EverysizeCheckout extends Plugin
{
    public const TECHNICAL_NAME = 'EverysizeCheckout';

    /**
     * Shopware runs the migrations after install(), so the takeover needs
     * postInstall(). The previous plugin may still be active here; what it
     * imports until it is deactivated is taken over on activation.
     */
    public function postInstall(InstallContext $installContext): void
    {
        // Not fatal here: nothing of the previous plugin is touched, and
        // activate() repeats the takeover — and refuses if it fails again.
        $this->takeOverLegacyPlugin(false);
    }

    public function activate(ActivateContext $activateContext): void
    {
        LegacyPlugin::assertInactive($this->getConnection());

        // Without its order assignments the plugin would not report shipping,
        // cancellations and returns of earlier orders — better not active.
        $this->takeOverLegacyPlugin(true);
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        $this->takeOverLegacyPlugin(false);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Only the plugin's own tables. The previous plugin's tables and
        // settings stay untouched: they belong to ITSEverysizeCheckout.
        (new UninstallService($this->getConnection(), $this->container->get(SystemConfigService::class)))->uninstall();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $locator = new FileLocator('Resources/config');

        $resolver = new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
        ]);

        $configLoader = new DelegatingLoader($resolver);

        $confDir = \rtrim($this->getPath(), '/').'/Resources/config';

        $configLoader->load($confDir.'/{packages}/*.yaml', 'glob');
    }

    private function takeOverLegacyPlugin(bool $failHard): void
    {
        $takeover = new LegacyTakeover($this->getConnection(), $this->container->get(SystemConfigService::class));

        try {
            $takeover->run();
        } catch (\Throwable $e) {
            if ($failHard) {
                throw LegacyPluginException::takeoverFailed($e);
            }

            try {
                $takeover->writeProtocol('Takeover from ITSEverysizeCheckout failed (repeated on activation)', $e->getMessage(), false);
            } catch (\Throwable) {
                // The protocol is only the messenger; installing must not fail on it.
            }
        }
    }

    private function getConnection(): Connection
    {
        return $this->container->get(Connection::class);
    }
}
