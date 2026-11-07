<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Karla\Delivery\MessageHandler\SyncProductUrlsMessageHandler;
use Karla\Delivery\Service\CatalogCleanupService;
use Karla\Delivery\Service\ProductSeoUrlPersister;
use Karla\Delivery\Service\ProductSyncService;
use Karla\Delivery\Subscriber\SeoUrlSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Content\Seo\SeoUrlPersister;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProductUrlQueueTest extends TestCase
{
    public static function persistenceCases(): array
    {
        return [
            'selected language including URL removal' => ['frontend.detail.page', ['product'], true, true, true],
            'other route' => ['frontend.navigation.page', ['category'], true, true, false],
            'empty batch' => ['frontend.detail.page', [], true, true, false],
            'disabled sync' => ['frontend.detail.page', ['product'], false, true, false],
            'shared catalog without a URL language' => ['frontend.detail.page', ['product'], true, null, true],
            'other language' => ['frontend.detail.page', ['product'], true, false, false],
        ];
    }

    #[DataProvider('persistenceCases')]
    public function testPersistenceQueuesOriginalIdsOnlyAfterCommitAndInSelectedLanguage(string $route, array $ids, bool $enabled, ?bool $selected, bool $queued): void
    {
        $context = Context::createDefaultContext();
        $channel = new SalesChannelEntity();
        $channel->setId('channel');
        $channel->setLanguageId($context->getLanguageId());
        $inner = $this->createMock(SeoUrlPersister::class);
        $persisted = false;
        $inner->expects(self::once())->method('updateSeoUrls')->with($context, $route, $ids, [], $channel)->willReturnCallback(static function () use (&$persisted) {
            $persisted = true;
        });
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getProductUrlLanguage')->willReturn($selected === null ? null : ($selected ? $context->getLanguageId() : 'other-language'));
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($enabled);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($queued ? self::once() : self::never())->method('dispatch')->willReturnCallback(static function ($message) use (&$persisted, $ids) {
            self::assertTrue($persisted);
            self::assertInstanceOf(AsyncMessageInterface::class, $message);
            self::assertInstanceOf(SyncProductUrlsMessage::class, $message);
            self::assertSame($ids, $message->productIds);
            self::assertSame('channel', $message->salesChannelId);

            return new Envelope($message);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $decorator = new ProductSeoUrlPersister($inner, $products, $config, $bus, $logger, $this->createMock(EntityRepository::class));
        $decorator->updateSeoUrls($context, $route, $ids, [], $channel);
    }

    public function testQueueFailureDoesNotBreakSeoPersistence(): void
    {
        $context = Context::createDefaultContext();
        $channel = new SalesChannelEntity();
        $channel->setId('channel');
        $inner = $this->createMock(SeoUrlPersister::class);
        $inner->expects(self::once())->method('updateSeoUrls');
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getProductUrlLanguage')->willReturn($context->getLanguageId());
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Queue unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Unable to queue indexed product URLs', ['error' => 'Queue unavailable']);
        (new ProductSeoUrlPersister($inner, $products, $config, $bus, $logger, $this->createMock(EntityRepository::class)))->updateSeoUrls($context, 'frontend.detail.page', ['product'], [], $channel);
    }

    public function testCorePersistenceErrorsStillPropagate(): void
    {
        $channel = new SalesChannelEntity();
        $inner = $this->createMock(SeoUrlPersister::class);
        $inner->method('updateSeoUrls')->willThrowException(new \RuntimeException('Core failure'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $decorator = new ProductSeoUrlPersister($inner, $this->createMock(ProductSyncService::class), $this->createMock(SystemConfigService::class), $bus, $this->createMock(LoggerInterface::class), $this->createMock(EntityRepository::class));
        $this->expectExceptionMessage('Core failure');
        $decorator->updateSeoUrls(Context::createDefaultContext(), 'frontend.detail.page', ['product'], [], $channel);
    }

    public static function collisionCases(): array
    {
        return ['zero path' => [false, false, false, '0'], 'array' => [false, false, false], 'entity and generator' => [true, false, false], 'global path' => [false, true, false], 'capture failure preserves core' => [false, false, true]];
    }

    #[DataProvider('collisionCases')]
    public function testDisplacedProductIsCapturedBeforePersistenceAndRefreshedAfterward(bool $entityInput, bool $global, bool $readFails, string $path = '/shared'): void
    {
        $context = Context::createDefaultContext();
        $channel = new SalesChannelEntity();
        $channel->setId('channel');
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getProductUrlLanguage')->willReturn($context->getLanguageId());
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);
        $data = ['foreignKey' => 'new-product', 'salesChannelId' => $global ? null : 'channel', 'seoPathInfo' => $path];
        $input = [$data, ['foreignKey' => 'invalid', 'seoPathInfo' => 'bad', 'error' => 'invalid']];
        if ($entityInput) {
            $url = $this->createMock(SeoUrlEntity::class);
            $url->method('jsonSerialize')->willReturn($data);
            $input = (static function () use ($url) {
                yield $url;
            })();
        }
        $existing = $this->createMock(SeoUrlEntity::class);
        $existing->method('getForeignKey')->willReturn('displaced');
        $existing->method('getSalesChannelId')->willReturn($global ? null : 'channel');
        $rows = $this->createMock(EntitySearchResult::class);
        $rows->method('getIterator')->willReturn(new \ArrayIterator([$existing]));
        $repository = $this->createMock(EntityRepository::class);
        $captured = false;
        $repository->expects(self::once())->method('search')->willReturnCallback(static function ($criteria) use (&$captured, $rows, $readFails, $global, $context, $path) {
            self::assertSame($context->getLanguageId(), $criteria->getFilters()[1]->getValue());
            self::assertSame($global ? null : 'channel', $criteria->getFilters()[2]->getValue());
            self::assertSame([ltrim($path, '/')], $criteria->getFilters()[4]->getValue());
            $captured = true;
            if ($readFails) {
                throw new \RuntimeException('Capture unavailable');
            }

            return $rows;
        });
        $inner = $this->createMock(SeoUrlPersister::class);
        $persisted = false;
        $inner->expects(self::once())->method('updateSeoUrls')->willReturnCallback(static function ($context, $route, $ids, $urls) use (&$captured, &$persisted) {
            self::assertTrue($captured);
            self::assertIsArray($urls);
            $persisted = true;
        });
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($message) use (&$messages, &$persisted) {
            self::assertTrue($persisted);
            $messages[$message->salesChannelId ?? 'all'] = $message->productIds;

            return new Envelope($message);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($readFails ? self::once() : self::never())->method('error');
        (new ProductSeoUrlPersister($inner, $products, $config, $bus, $logger, $repository))->updateSeoUrls($context, 'frontend.detail.page', ['new-product'], $input, $channel);
        self::assertSame($readFails || $global ? ['new-product'] : ['new-product', 'displaced'], $messages['channel']);
        if ($global) {
            self::assertSame(['displaced'], $messages['all']);
        }
    }

    public function testGlobalRefreshIsolatesDestinationFailuresAndSkipsMissingConfiguration(): void
    {
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getSalesChannelIds')->willReturn(['broken', 'healthy', 'missing-slug', 'missing-api', 'missing-user', 'missing-key']);
        $products->method('getShopSlug')->willReturnCallback(static fn ($channel) => $channel === 'missing-slug' ? '' : 'shop');
        $products->expects(self::once())->method('syncIndexedProductIds')->with(['product'], 'healthy');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn ($key, $channel) => match ($key) {
            'KarlaDelivery.config.productSyncEnabled' => true,
            'KarlaDelivery.config.apiUrl' => $channel === 'missing-api' ? '' : 'https://api.example',
            'KarlaDelivery.config.apiUsername' => $channel === 'missing-user' ? '' : 'user',
            'KarlaDelivery.config.apiKey' => $channel === 'missing-key' ? '' : 'key',
            default => null,
        });
        $cleanup = $this->createMock(CatalogCleanupService::class);
        $locks = [];
        $cleanup->method('withCatalogLock')->willReturnCallback(static function ($channel, $work) use (&$locks) {
            $locks[] = $channel;
            if ($channel === 'broken') {
                throw new \RuntimeException('API unavailable');
            }
            $work();
        });
        $cleanup->method('retireUnavailable')->willReturn(['product']);
        $queued = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($message) use (&$queued) {
            $queued[] = $message;

            return new Envelope($message);
        });
        $handler = new SyncProductUrlsMessageHandler($products, $config, $cleanup, $bus);
        $handler(new SyncProductUrlsMessage(['product'], null, false, true));
        self::assertSame([], $locks);
        self::assertSame(['broken', 'healthy'], array_map(static fn ($message) => $message->salesChannelId, $queued));

        try {
            $handler($queued[0]);
            self::fail('Broken destination must retry its own message');
        } catch (\RuntimeException $error) {
            self::assertSame('API unavailable', $error->getMessage());
        }
        $handler($queued[1]);
        $handler(new SyncProductUrlsMessage(['product'], 'missing-slug'));
        $handler(new SyncProductUrlsMessage(['product'], 'unknown'));
        self::assertSame(['broken', 'healthy'], $locks);
    }

    public static function handlerCases(): array
    {
        return [
            'legacy' => [null, 'a', true, [null]],
            'scoped' => [['a', 'b'], 'a', true, ['a']],
            'global manual edit' => [['a', 'b'], null, true, []],
            'disabled' => [['a'], 'a', false, []],
        ];
    }

    #[DataProvider('handlerCases')]
    public function testQueuedRefreshUsesOnlyEnabledMatchingCatalogs(?array $channels, ?string $messageChannel, bool $enabled, array $expected): void
    {
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getShopSlug')->willReturn('shop');
        $products->method('getSalesChannelIds')->willReturn($channels);
        $seen = [];
        $products->expects(self::exactly(count($expected)))->method('syncIndexedProductIds')->willReturnCallback(static function ($ids, $channel) use (&$seen) {
            self::assertSame(['product'], $ids);
            $seen[] = $channel;
        });
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($enabled);
        $cleanup = $this->createMock(CatalogCleanupService::class);
        $cleanup->method('withCatalogLock')->willReturnCallback(static function ($channel, $work) {
            $work();
        });
        $cleanup->method('retireUnavailable')->willReturn(['product']);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn ($message) => new Envelope($message));
        (new SyncProductUrlsMessageHandler($products, $config, $cleanup, $bus))(new SyncProductUrlsMessage(['product'], $messageChannel));
        self::assertSame($expected, $seen);
    }

    public function testReconciliationSnapshotsIdsBeforeQueuingBoundedBatches(): void
    {
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getShopSlug')->willReturn('shop');
        $products->method('getSalesChannelIds')->willReturn(['a']);
        $products->expects(self::never())->method('syncIndexedProductIds');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);
        $cleanup = $this->createMock(CatalogCleanupService::class);
        $locked = false;
        $cleanup->expects(self::once())->method('withCatalogLock')->with('a', self::isType('callable'))->willReturnCallback(static function ($channel, $work) use (&$locked) {
            $locked = true;
            $work();
            $locked = false;
        });
        $ids = array_map('strval', range(1, 101));
        $readComplete = false;
        $cleanup->expects(self::once())->method('catalogVariantIds')->with('a')->willReturnCallback(static function () use (&$readComplete, $ids, &$locked) {
            self::assertTrue($locked);
            $readComplete = true;

            return $ids;
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $sizes = [];
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function ($message) use (&$sizes, &$readComplete) {
            self::assertTrue($readComplete);
            self::assertSame('a', $message->salesChannelId);
            self::assertFalse($message->reconcile);
            $sizes[] = count($message->productIds);

            return new Envelope($message);
        });
        (new SyncProductUrlsMessageHandler($products, $config, $cleanup, $bus))(new SyncProductUrlsMessage([], 'a', true));
        self::assertSame([100, 1], $sizes);
    }

    public function testRetirementCannotBeUndoneByUpsertingUnavailableIds(): void
    {
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getShopSlug')->willReturn('shop');
        $products->method('getSalesChannelIds')->willReturn(null);
        $products->expects(self::never())->method('syncIndexedProductIds');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);
        $cleanup = $this->createMock(CatalogCleanupService::class);
        $cleanup->method('withCatalogLock')->willReturnCallback(static function ($channel, $work) {
            $work();
        });
        $cleanup->expects(self::once())->method('retireUnavailable')->with(['inactive'], null)->willReturn([]);
        (new SyncProductUrlsMessageHandler($products, $config, $cleanup, $this->createMock(MessageBusInterface::class)))(new SyncProductUrlsMessage(['inactive'], null));
    }

    public function testAvailabilityOnlyRefreshNeverWritesPreIndexUrls(): void
    {
        $products = $this->createMock(ProductSyncService::class);
        $products->method('getShopSlug')->willReturn('shop');
        $products->method('getSalesChannelIds')->willReturn(['a']);
        $products->expects(self::never())->method('syncIndexedProductIds');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);
        $cleanup = $this->createMock(CatalogCleanupService::class);
        $cleanup->method('withCatalogLock')->willReturnCallback(static function ($channel, $work) {
            $work();
        });
        $cleanup->expects(self::once())->method('retireUnavailable')->with(['active'], 'a')->willReturn(['active']);
        (new SyncProductUrlsMessageHandler($products, $config, $cleanup, $this->createMock(MessageBusInterface::class)))(new SyncProductUrlsMessage(['active'], 'a', refreshUrls: false));
    }

    public function testManualSeoWritesAreGroupedByChannelAndDeduplicated(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $rows = [];
        foreach ([['a', 'product'], ['a', 'product'], [null, 'shared']] as [$channel, $product]) {
            $row = $this->createMock(SeoUrlEntity::class);
            $row->method('getSalesChannelId')->willReturn($channel);
            $row->method('getForeignKey')->willReturn($product);
            $rows[] = $row;
        }
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $repository->expects(self::once())->method('search')->willReturnCallback(static function ($criteria) use ($result) {
            self::assertSame(['seo-id'], $criteria->getIds());
            self::assertSame('routeName', $criteria->getFilters()[0]->getField());
            self::assertSame('frontend.detail.page', $criteria->getFilters()[0]->getValue());

            return $result;
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $messages = [];
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function ($message) use (&$messages) {
            $messages[] = [$message->salesChannelId, $message->productIds];

            return new Envelope($message);
        });
        $subscriber = new SeoUrlSubscriber($repository, $bus, $this->createMock(LoggerInterface::class));
        self::assertSame(['seo_url.written' => 'onSeoUrlWritten'], SeoUrlSubscriber::getSubscribedEvents());
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn(['seo-id']);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $subscriber->onSeoUrlWritten($event);
        self::assertSame([['a', ['product']], [null, ['shared']]], $messages);
    }

    public function testEmptyManualWriteDoesNotSelectAllSeoRows(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new SeoUrlSubscriber($repository, $bus, $this->createMock(LoggerInterface::class)))->onSeoUrlWritten($this->createMock(EntityWrittenEvent::class));
    }

    public function testManualSeoQueueErrorsDoNotBreakTheWrite(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willThrowException(new \RuntimeException('Read failure'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Unable to queue edited product SEO URLs', ['error' => 'Read failure']);
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn(['seo-id']);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        (new SeoUrlSubscriber($repository, $this->createMock(MessageBusInterface::class), $logger))->onSeoUrlWritten($event);
    }
}
