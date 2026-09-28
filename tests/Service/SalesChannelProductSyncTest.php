<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Doctrine\DBAL\Connection;
use Karla\Delivery\Message\SyncAllProductsMessage;
use Karla\Delivery\MessageHandler\SyncAllProductsMessageHandler;
use Karla\Delivery\Service\ProductSyncService;
use Karla\Delivery\Subscriber\ProductSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SalesChannelProductSyncTest extends TestCase
{
    public function testLegacyAndScopedChannelDiscovery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(false, 1);
        $connection->method('fetchFirstColumn')->willReturn(['first', 'second']);
        $service = new ProductSyncService($this->createMock(EntityRepository::class), $this->createMock(HttpClientInterface::class), $this->createMock(SystemConfigService::class), new NullLogger(), $connection);
        self::assertNull($service->getSalesChannelIds());
        self::assertSame(['first', 'second'], $service->getSalesChannelIds());
    }

    public function testTwoCatalogsUseTheirOwnVisibilityFilterShopAndCredentials(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key, ?string $channel = null) => match ($key) {
            'KarlaDelivery.config.salesChannelMapping' => 'second:mapped-second',
            'KarlaDelivery.config.shopSlug' => $channel,
            'KarlaDelivery.config.apiUsername' => $channel . '-user',
            'KarlaDelivery.config.apiKey' => $channel . '-key',
            'KarlaDelivery.config.apiUrl' => 'https://api.example',
            default => null,
        });
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('search')->willReturnCallback(static function (Criteria $criteria, Context $context) {
            self::assertTrue($context->considerInheritance());
            $filters = $criteria->getFilters();
            self::assertInstanceOf(\Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter::class, $filters[0]);
            self::assertSame('visibilities.salesChannelId', $filters[0]->getField());
            $channel = $filters[0]->getValue();
            $product = new ProductEntity();
            $product->setId($channel . '-product');
            $product->setProductNumber($channel . '-sku');
            $product->setName($channel . '-title');
            $product->setParentId(null);
            $product->setChildCount(0);
            $product->setActive(true);

            return new EntitySearchResult('product', 1, new ProductCollection([$product]), null, $criteria, $context);
        });
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $client = $this->createMock(HttpClientInterface::class);
        $seen = [];
        $client->expects(self::exactly(4))->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use (&$seen, $response) {
            $channel = count($seen) < 2 ? 'first' : 'second';
            $slug = $channel === 'second' ? 'mapped-second' : 'first';
            self::assertStringStartsWith('https://api.example/v1/shops/' . $slug . '/products', $url);
            self::assertSame('Basic ' . base64_encode($channel . '-user:' . $channel . '-key'), $options['headers']['Authorization']);
            if ($method === 'POST') {
                self::assertStringContainsString($channel . '-product', $options['body']);
            }
            $seen[] = $channel;

            return $response;
        });
        $service = new ProductSyncService($repository, $client, $config, new NullLogger(), $this->createMock(Connection::class));
        foreach (['first', 'second'] as $channel) {
            self::assertFalse($service->syncProductBatch(0, 50, $channel));
            $service->deleteProduct($channel . '-product', $channel);
        }
    }

    public static function batchStates(): array
    {
        return ['continue' => [true, true], 'complete' => [true, false], 'disabled' => [false, false]];
    }

    #[DataProvider('batchStates')]
    public function testQueuedBatchesPreserveChannelAndRespectDisable(bool $enabled, bool $more): void
    {
        $service = $this->createMock(ProductSyncService::class);
        $service->expects($enabled ? self::once() : self::never())->method('syncProductBatch')->with(50, 50, 'second')->willReturn($more);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with('KarlaDelivery.config.productSyncEnabled', 'second')->willReturn($enabled);
        if ($enabled && ! $more) {
            $config->expects(self::once())->method('set')->with('KarlaDelivery.config.productSyncStatus', 'completed', 'second');
        }
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($more ? self::once() : self::never())->method('dispatch')->willReturnCallback(static function (SyncAllProductsMessage $next) {
            self::assertSame('second', $next->getSalesChannelId());
            self::assertSame(100, $next->getOffset());

            return new Envelope($next);
        });
        (new SyncAllProductsMessageHandler($service, new NullLogger(), $bus, $config))(new SyncAllProductsMessage(50, 50, 'second'));
    }

    public function testGlobalQueueFansOutOnlyToEnabledChannels(): void
    {
        $service = $this->createMock(ProductSyncService::class);
        $service->method('getSalesChannelIds')->willReturn(['first', 'second']);
        $service->expects(self::never())->method('syncProductBatch');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key, ?string $channel) => $channel === 'second');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SyncAllProductsMessage $message) {
            self::assertSame('second', $message->getSalesChannelId());

            return new Envelope($message);
        });
        (new SyncAllProductsMessageHandler($service, new NullLogger(), $bus, $config))(new SyncAllProductsMessage());
    }

    public function testRealtimeWritesAndDeletesCarryTheChannel(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key, ?string $channel = null) => $key === 'KarlaDelivery.config.productSyncEnabled' ? true : 'configured');
        $repository = $this->createMock(EntityRepository::class);
        $product = new ProductEntity();
        $product->setId('product');
        $product->setProductNumber('sku');
        $product->setName('Product');
        $product->setActive(true);
        $product->setParentId(null);
        $product->setChildCount(0);
        $repository->method('search')->willReturnCallback(static function (Criteria $criteria, Context $context) use ($product) {
            self::assertTrue($context->considerInheritance());

            return new EntitySearchResult('product', 1, new ProductCollection([$product]), null, $criteria, $context);
        });
        $service = $this->createMock(ProductSyncService::class);
        $service->method('getSalesChannelIds')->willReturn(['second']);
        $service->expects(self::once())->method('scopeCriteria')->with(self::isInstanceOf(Criteria::class), 'second');
        $service->expects(self::once())->method('upsertProduct')->with($product, null, 'second');
        $service->expects(self::once())->method('deleteProduct')->with('product', 'second');
        $subscriber = new ProductSubscriber($config, new NullLogger(), $repository, $service);
        $context = Context::createDefaultContext();
        $written = $this->createMock(EntityWrittenEvent::class);
        $written->method('getContext')->willReturn($context);
        $written->method('getIds')->willReturn(['product']);
        $deleted = $this->createMock(EntityDeletedEvent::class);
        $deleted->method('getIds')->willReturn(['product']);
        $subscriber->onProductWritten($written);
        $subscriber->onProductDeleted($deleted);
        self::assertFalse($context->considerInheritance());
    }

    public function testChannelDiscoveryFailureDoesNotBreakProductWritesOrDeletes(): void
    {
        $service = $this->createMock(ProductSyncService::class);
        $service->method('getSalesChannelIds')->willThrowException(new \RuntimeException('Database unavailable'));
        $subscriber = new ProductSubscriber($this->createMock(SystemConfigService::class), new NullLogger(), $this->createMock(EntityRepository::class), $service);
        $service->expects(self::never())->method('upsertProduct');
        $service->expects(self::never())->method('deleteProduct');
        $subscriber->onProductWritten($this->createMock(EntityWrittenEvent::class));
        $subscriber->onProductDeleted($this->createMock(EntityDeletedEvent::class));
    }
    public function testCoordinatorCompletesWithNoEnabledChannels(): void
    {
        $service = $this->createMock(ProductSyncService::class);
        $service->method('getSalesChannelIds')->willReturn(['disabled']);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(false);
        $config->expects(self::once())->method('set')->with('KarlaDelivery.config.productSyncStatus', 'completed');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new SyncAllProductsMessageHandler($service, new NullLogger(), $bus, $config))(new SyncAllProductsMessage());
    }

}
