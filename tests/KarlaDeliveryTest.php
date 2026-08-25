<?php

namespace Karla\Delivery\Tests;

use Karla\Delivery\KarlaDelivery;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KarlaDeliveryTest extends TestCase
{
    private KarlaDelivery $plugin;
    /** @var EntityRepository&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $customFieldSetRepository;

    protected function setUp(): void
    {
        $this->plugin = new KarlaDelivery(true, '');
        $this->customFieldSetRepository = $this->createMock(EntityRepository::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('custom_field_set.repository')
            ->willReturn($this->customFieldSetRepository);
        $this->plugin->setContainer($container);
    }

    /**
     * Test the install method
     */
    public function testInstall()
    {
        $installContext = $this->createMock(InstallContext::class);
        $context = Context::createDefaultContext();
        $installContext->method('getContext')->willReturn($context);
        $this->customFieldSetRepository->expects($this->once())->method('upsert');

        // This should not throw an exception
        $this->plugin->install($installContext);

        // Just verify it completes without error
        $this->assertTrue(true);
    }

    /**
     * Test the uninstall method
     */
    public function testUninstall()
    {
        $uninstallContext = $this->createMock(UninstallContext::class);
        $context = Context::createDefaultContext();
        $uninstallContext->method('getContext')->willReturn($context);
        $uninstallContext->method('keepUserData')->willReturn(false);
        $this->customFieldSetRepository->expects($this->once())->method('delete')->with($this->anything(), $context);

        // This should not throw an exception
        $this->plugin->uninstall($uninstallContext);

        // Just verify it completes without error
        $this->assertTrue(true);
    }

    public function testUninstallKeepsCustomFieldsWhenUserDataIsPreserved()
    {
        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('keepUserData')->willReturn(true);
        $this->customFieldSetRepository->expects($this->never())->method('delete');

        $this->plugin->uninstall($uninstallContext);
    }

    /**
     * Test the activate method
     */
    public function testActivate()
    {
        $activateContext = $this->createMock(ActivateContext::class);
        $context = Context::createDefaultContext();
        $activateContext->method('getContext')->willReturn($context);
        $this->customFieldSetRepository->expects($this->once())->method('upsert');

        // This should not throw an exception
        $this->plugin->activate($activateContext);

        // Just verify it completes without error
        $this->assertTrue(true);
    }

    /**
     * Test the update method
     */
    public function testUpdate()
    {
        $updateContext = $this->createMock(UpdateContext::class);
        $context = Context::createDefaultContext();
        $updateContext->method('getContext')->willReturn($context);
        $this->customFieldSetRepository->expects($this->once())->method('upsert');

        // This should not throw an exception
        $this->plugin->update($updateContext);

        // Just verify it completes without error
        $this->assertTrue(true);
    }

    public function testInstallFailsWhenContainerIsNotInitialized(): void
    {
        $plugin = new KarlaDelivery(true, '');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Plugin container is not initialized');

        $plugin->install($this->createMock(InstallContext::class));
    }

    public function testInstallFailsWhenCustomFieldRepositoryIsUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('custom_field_set.repository')->willReturn(new \stdClass());
        $this->plugin->setContainer($container);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Custom field set repository is not available');

        $this->plugin->install($this->createMock(InstallContext::class));
    }
}
