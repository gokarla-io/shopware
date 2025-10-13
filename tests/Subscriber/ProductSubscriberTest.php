<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Service\ProductSyncService;
use Karla\Delivery\Subscriber\ProductSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Subscriber\ProductSubscriber
 */
final class ProductSubscriberTest extends TestCase
{
    /** @var SystemConfigService&MockObject */
    private SystemConfigService $systemConfigServiceMock;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $loggerMock;

    /** @var EntityRepository&MockObject */
    private EntityRepository $productRepositoryMock;

    /** @var ProductSyncService&MockObject */
    private ProductSyncService $productSyncServiceMock;

    private ProductSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->productRepositoryMock = $this->createMock(EntityRepository::class);
        $this->productSyncServiceMock = $this->createMock(ProductSyncService::class);

        $this->subscriber = new ProductSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->productRepositoryMock,
            $this->productSyncServiceMock
        );
    }

    /**
     * @covers ::__construct
     * @covers ::getSubscribedEvents
     */
    public function testGetSubscribedEvents(): void
    {
        $events = ProductSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ProductEvents::PRODUCT_WRITTEN_EVENT, $events);
        $this->assertEquals('onProductWritten', $events[ProductEvents::PRODUCT_WRITTEN_EVENT]);
        $this->assertArrayHasKey(ProductEvents::PRODUCT_DELETED_EVENT, $events);
        $this->assertEquals('onProductDeleted', $events[ProductEvents::PRODUCT_DELETED_EVENT]);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenWhenDisabled(): void
    {
        $this->systemConfigServiceMock->method('get')
            ->with('KarlaDelivery.config.productSyncEnabled')
            ->willReturn(false);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->expects($this->never())->method('getIds');

        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenSkipsWhenConfigMissing(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, ''], // Missing
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn([Uuid::randomHex()]);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Product sync skipped - missing configuration', [
                'component' => 'product.sync',
            ]);

        $this->productRepositoryMock->expects($this->never())->method('search');

        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenSyncsActiveProduct(): void
    {
        $productId = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        // Mock product
        $product = $this->createMock(ProductEntity::class);
        $product->method('getActive')->willReturn(true);
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $this->productRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);

        $this->productSyncServiceMock->expects($this->once())
            ->method('upsertProduct')
            ->with($product);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Product synced to Karla', [
                'component' => 'product.sync',
                'product_number' => 'PROD-001',
                'product_name' => 'Test Product',
            ]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn([$productId]);

        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenSkipsInactiveProduct(): void
    {
        $productId = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        // Mock inactive product
        $product = $this->createMock(ProductEntity::class);
        $product->method('getActive')->willReturn(false);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $this->productRepositoryMock->method('search')->willReturn($searchResult);

        // Should not sync
        $this->productSyncServiceMock->expects($this->never())->method('upsertProduct');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn([$productId]);

        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenHandlesException(): void
    {
        $productId = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn([$productId]);

        // Product repository throws exception
        $this->productRepositoryMock->method('search')
            ->willThrowException(new \Exception('Database error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Unexpected error during product sync',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.sync'
                        && $context['error'] === 'Database error';
                })
            );

        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::onProductDeleted
     */
    public function testOnProductDeletedWhenDisabled(): void
    {
        $this->systemConfigServiceMock->method('get')
            ->with('KarlaDelivery.config.productSyncEnabled')
            ->willReturn(false);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->expects($this->never())->method('getIds');

        $this->subscriber->onProductDeleted($event);
    }

    /**
     * @covers ::onProductDeleted
     */
    public function testOnProductDeletedSkipsWhenConfigMissing(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, ''], // Missing
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getIds')->willReturn([Uuid::randomHex()]);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Product deletion sync skipped - missing configuration', [
                'component' => 'product.sync',
            ]);

        $this->productSyncServiceMock->expects($this->never())->method('deleteProduct');

        $this->subscriber->onProductDeleted($event);
    }

    /**
     * @covers ::onProductDeleted
     */
    public function testOnProductDeletedDeletesProducts(): void
    {
        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        $this->productSyncServiceMock->expects($this->exactly(2))
            ->method('deleteProduct')
            ->willReturnCallback(function ($id) use ($productId1, $productId2) {
                $this->assertContains($id, [$productId1, $productId2]);

                return null;
            });

        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->with('Product deleted from Karla', $this->callback(function ($context) {
                return $context['component'] === 'product.sync'
                    && isset($context['product_id']);
            }));

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getIds')->willReturn([$productId1, $productId2]);

        $this->subscriber->onProductDeleted($event);
    }

    /**
     * @covers ::onProductDeleted
     */
    public function testOnProductDeletedHandlesException(): void
    {
        $productId = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        $this->productSyncServiceMock->method('deleteProduct')
            ->willThrowException(new \Exception('API error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Unexpected error during product deletion sync',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.sync'
                        && $context['error'] === 'API error';
                })
            );

        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getIds')->willReturn([$productId]);

        $this->subscriber->onProductDeleted($event);
    }

    /**
     * @covers ::onProductWritten
     */
    public function testOnProductWrittenSyncsVariantsForParentProduct(): void
    {
        $parentId = Uuid::randomHex();
        $variant1Id = Uuid::randomHex();
        $variant2Id = Uuid::randomHex();

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
        ]);

        // Mock parent product with variants
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getActive')->willReturn(true);
        $parentProduct->method('getProductNumber')->willReturn('PARENT-001');
        $parentProduct->method('getName')->willReturn('Parent Product');
        $parentProduct->method('getParentId')->willReturn(null);
        $parentProduct->method('getChildCount')->willReturn(2);
        $parentProduct->method('getId')->willReturn($parentId);

        // Mock variant 1
        $variant1 = $this->createMock(ProductEntity::class);
        $variant1->method('getProductNumber')->willReturn('VARIANT-001');

        // Mock variant 2
        $variant2 = $this->createMock(ProductEntity::class);
        $variant2->method('getProductNumber')->willReturn('VARIANT-002');

        // First search returns parent
        $parentSearchResult = $this->createMock(EntitySearchResult::class);
        $parentSearchResult->method('getIterator')->willReturn(new \ArrayIterator([$parentProduct]));

        // Second search returns variants
        $variantSearchResult = $this->createMock(EntitySearchResult::class);
        $variantSearchResult->method('getIterator')->willReturn(new \ArrayIterator([$variant1, $variant2]));
        $variantSearchResult->method('count')->willReturn(2);

        // Expect 2 repository searches: parent + variants
        $this->productRepositoryMock->expects($this->exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls($parentSearchResult, $variantSearchResult);

        // Should sync both variants (not the parent)
        $syncedProducts = [];
        $this->productSyncServiceMock->expects($this->exactly(2))
            ->method('upsertProduct')
            ->willReturnCallback(function ($product) use (&$syncedProducts) {
                $syncedProducts[] = $product;
            });

        $this->loggerMock->expects($this->atLeast(1))
            ->method('debug');

        $loggedVariants = [];
        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$loggedVariants) {
                if ($message === 'Variant synced to Karla') {
                    $loggedVariants[] = $context['variant_number'];
                }
            });

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn([$parentId]);

        $this->subscriber->onProductWritten($event);

        // Assert both variants were synced
        $this->assertCount(2, $syncedProducts);
        $this->assertSame($variant1, $syncedProducts[0]);
        $this->assertSame($variant2, $syncedProducts[1]);

        // Assert both variants were logged
        $this->assertCount(2, $loggedVariants);
        $this->assertContains('VARIANT-001', $loggedVariants);
        $this->assertContains('VARIANT-002', $loggedVariants);
    }
}
