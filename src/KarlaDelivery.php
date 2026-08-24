<?php

declare(strict_types=1);

namespace Karla\Delivery;

use Karla\Delivery\Service\CustomFieldsInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class KarlaDelivery extends Plugin
{
    /**
     * @codeCoverageIgnore
     * This method loads Shopware configuration and requires complex integration test setup.
     */
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

        $confDir = \rtrim($this->getPath(), '/') . '/Resources/config';

        $configLoader->load($confDir . '/{packages}/*.yaml', 'glob');
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->getCustomFieldsInstaller()->install($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->getCustomFieldsInstaller()->install($activateContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->getCustomFieldsInstaller()->install($updateContext->getContext());
    }

    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        if (! isset($this->container)) {
            throw new \LogicException('Plugin container is not initialized');
        }

        $repository = $this->container->get('custom_field_set.repository');
        if (! $repository instanceof EntityRepository) {
            throw new \LogicException('Custom field set repository is not available');
        }

        return new CustomFieldsInstaller($repository);
    }
}
