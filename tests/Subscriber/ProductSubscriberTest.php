<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Doctrine\DBAL\Connection;
use Karla\Delivery\Message\SyncProductUrlsMessage;
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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $bus;

    private ProductSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->productRepositoryMock = $this->createMock(EntityRepository::class);
        $this->productSyncServiceMock = $this->createMock(ProductSyncService::class);
        $this->productSyncServiceMock->method('getShopSlug')->willReturnCallback(fn ($channel) => (string) $this->systemConfigServiceMock->get('KarlaDelivery.config.shopSlug', $channel));

        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(static fn ($message) => new Envelope($message));
        $this->subscriber = new ProductSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->productRepositoryMock,
            $this->productSyncServiceMock,
            $this->bus
        );
    }

    public static function parentChildCounts(): array
    {
        return ['indexed' => [2], 'index pending' => [0]];
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
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
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
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
     * @covers ::onProductWritten
     */
    public static function missingDestinationCases(): array
    {
        return ['global missing slug' => [null], 'scoped headless missing slug' => ['headless']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingDestinationCases')]
    public function testOnProductWrittenSkipsWhenConfigMissing(?string $channel): void
    {
        $this->productSyncServiceMock->method('getSalesChannelIds')->willReturn($channel === null ? null : [$channel]);
        $this->bus->expects(self::never())->method('dispatch');
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', $channel, true],
            ['KarlaDelivery.config.shopSlug', $channel, ''], // Missing
            ['KarlaDelivery.config.apiUsername', $channel, 'user'],
            ['KarlaDelivery.config.apiKey', $channel, 'key'],
            ['KarlaDelivery.config.apiUrl', $channel, 'https://api.test.com'],
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
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
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

        // Standalone products are synced in ONE batched call
        $this->productSyncServiceMock->expects($this->once())
            ->method('upsertProducts')
            ->with([$product]);
        $this->productSyncServiceMock->expects($this->never())->method('upsertProduct');

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
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
     * @covers ::onProductWritten
     */
    public static function availabilityQueueCases(): array
    {
        return ['queued without SEO' => [false], 'queue failure is logged' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('availabilityQueueCases')]
    public function testOnProductWrittenSkipsInactiveProduct(bool $queueFails): void
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
        $this->productSyncServiceMock->expects($this->never())->method('upsertProducts');

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn([$productId]);

        if ($queueFails) {
            $this->bus->method('dispatch')->willThrowException(new \RuntimeException('Queue down'));
            $this->loggerMock->expects(self::once())->method('error')->with('Unable to queue product availability cleanup', ['error' => 'Queue down']);
        } else {
            $this->bus->expects(self::once())->method('dispatch')->willReturnCallback(static function ($message) use ($productId) {
                self::assertInstanceOf(SyncProductUrlsMessage::class, $message);
                self::assertSame([$productId], $message->productIds);
                self::assertFalse($message->refreshUrls);

                return new Envelope($message);
            });
        }
        $this->subscriber->onProductWritten($event);
    }

    /**
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
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
     * @covers ::processProductDeleted
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
     * @covers ::processProductDeleted
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
     * @covers ::processProductDeleted
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
     * @covers ::processProductDeleted
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
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
     * @covers ::onProductWritten
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parentChildCounts')]
    public function testOnProductWrittenSyncsVariantsForParentProduct(int $childCount): void
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
        $parentProduct->method('getChildCount')->willReturn($childCount);
        $this->productSyncServiceMock->expects($childCount === 0 ? $this->once() : $this->never())->method('hasVariants')->with($parentId)->willReturn(true);
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

        // Should sync both variants (not the parent) in ONE batched call,
        // so product URLs resolve with a single SEO query instead of N
        $syncedProducts = [];
        $syncedParent = null;
        $this->productSyncServiceMock->expects($this->once())
            ->method('upsertProducts')
            ->willReturnCallback(function (array $products, $parent) use (&$syncedProducts, &$syncedParent) {
                $syncedProducts = $products;
                $syncedParent = $parent;
            });
        $this->productSyncServiceMock->expects($this->never())->method('upsertProduct');

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

        // Assert both variants were synced with their parent
        $this->assertCount(2, $syncedProducts);
        $this->assertSame($variant1, $syncedProducts[0]);
        $this->assertSame($variant2, $syncedProducts[1]);
        $this->assertSame($parentProduct, $syncedParent);

        // Assert both variants were logged
        $this->assertCount(2, $loggedVariants);
        $this->assertContains('VARIANT-001', $loggedVariants);
        $this->assertContains('VARIANT-002', $loggedVariants);
    }

    /**
     * Regression: a write touching only variants used to PUT title: null
     * because their parent was never loaded, so the loaded parent's real
     * title was never sent (and older APIs rejected the null with 422).
     *
     * @covers ::processProductWritten
     * @covers ::isSyncConfigured
     * @covers ::syncProducts
     * @covers ::onProductWritten
     */
    public function testChildOnlyWriteSendsParentTitleWithOneParentLookup(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncEnabled', null, true],
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);
        $variants = [];
        foreach (['red' => 'Red', 'blue' => null] as $id => $name) {
            $variant = $this->createMock(ProductEntity::class);
            $variant->method('getId')->willReturn($id);
            $variant->method('getParentId')->willReturn('parent');
            $variant->method('getActive')->willReturn(true);
            $variant->method('getProductNumber')->willReturn('SW-' . $id);
            $variant->method('getName')->willReturn($name);
            $variants[] = $variant;
        }
        $parent = $this->createMock(ProductEntity::class);
        $parent->method('getId')->willReturn('parent');
        $parent->method('getName')->willReturn('Parent title');
        $results = [$variants, [$parent]];
        $this->productRepositoryMock->expects(self::exactly(2))->method('search')
            ->willReturnCallback(function (Criteria $criteria) use (&$results) {
                if (count($results) === 1) {
                    self::assertSame(['parent'], $criteria->getIds());
                }
                $result = $this->createMock(EntitySearchResult::class);
                $result->method('getIterator')->willReturn(new \ArrayIterator(array_shift($results)));

                return $result;
            });
        $requests = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$requests, $response): ResponseInterface {
                $requests[] = [$method, $url, json_decode((string) $options['body'], true)];

                return $response;
            }
        );
        $service = new ProductSyncService(
            $this->productRepositoryMock,
            $httpClient,
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->createMock(Connection::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class)
        );
        $this->loggerMock->expects(self::never())->method('error');
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $event->method('getIds')->willReturn(['red', 'blue']);

        (new ProductSubscriber($this->systemConfigServiceMock, $this->loggerMock, $this->productRepositoryMock, $service, $this->bus))
            ->onProductWritten($event);

        $url = 'https://api.test.com/v1/shops/test-shop/products/parent/variants/';
        self::assertSame([
            ['PUT', $url . 'red', ['title' => 'Parent title', 'variant_title' => 'Red', 'price' => null, 'sku' => 'SW-red']],
            ['PUT', $url . 'blue', ['title' => 'Parent title', 'variant_title' => 'SW-blue', 'price' => null, 'sku' => 'SW-blue']],
        ], $requests);
    }
}
