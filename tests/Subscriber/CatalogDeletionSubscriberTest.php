<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Karla\Delivery\Subscriber\CatalogDeletionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CatalogDeletionSubscriberTest extends TestCase
{
    public static function deletions(): array
    {
        return ['visibility' => ['product_visibility', false, false], 'canonical SEO' => ['seo_url', false, false], 'global SEO' => ['seo_url', true, false], 'reset' => ['seo_url', true, true], 'queue failure' => ['seo_url', false, false, true]];
    }

    #[DataProvider('deletions')]
    public function testSnapshotSurvivesTheDeletionAndQueuesOnlyAffectedProducts(string $entity, bool $global, bool $reset, bool $fails = false): void
    {
        $visibilities = $this->createMock(EntityRepository::class);
        $seo = $this->createMock(EntityRepository::class);
        $repository = $entity === 'seo_url' ? $seo : $visibilities;
        $rows = [];
        foreach (['deleted', 'deleted-too'] as $id) {
            $row = $this->createMock($entity === 'seo_url' ? SeoUrlEntity::class : ProductVisibilityEntity::class);
            $row->method('getId')->willReturn($id);
            $row->method($entity === 'seo_url' ? 'getForeignKey' : 'getProductId')->willReturn('product');
            $row->method('getSalesChannelId')->willReturn($global ? null : 'a');
            $rows[] = $row;
        }
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $repository->expects(self::once())->method('search')->willReturnCallback(static function ($criteria) use ($entity, $result) {
            self::assertSame(['deleted', 'deleted-too'], $criteria->getIds());
            self::assertCount($entity === 'seo_url' ? 2 : 0, $criteria->getFilters());

            return $result;
        });
        $commands = [$this->createMock(WriteCommand::class)];
        foreach (['deleted', 'deleted-too', null] as $id) {
            $command = $this->createMock(DeleteCommand::class);
            $command->method('getEntityName')->willReturn($entity);
            $command->method('getDecodedPrimaryKey')->willReturn($id === null ? [] : ['id' => $id]);
            $commands[] = $command;
        }
        $event = $this->createMock(PreWriteValidationEvent::class);
        $event->method('getCommands')->willReturn($commands);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($reset ? self::never() : self::once())->method('dispatch')->willReturnCallback(static function ($message) use ($global, $fails) {
            if ($fails) {
                throw new \RuntimeException('Queue failure');
            }
            self::assertInstanceOf(SyncProductUrlsMessage::class, $message);
            self::assertSame(['product'], $message->productIds);
            self::assertSame($global ? null : 'a', $message->salesChannelId);

            return new Envelope($message);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($fails ? self::once() : self::never())->method('error')->with('Unable to queue catalog deletion cleanup', ['error' => 'Queue failure']);
        $subscriber = new CatalogDeletionSubscriber($visibilities, $seo, $bus, $logger);
        self::assertArrayHasKey(PreWriteValidationEvent::class, $subscriber::getSubscribedEvents());
        $subscriber->beforeDelete($event);
        if ($reset) {
            $subscriber->reset();
        }
        $deleted = $this->createMock(EntityDeletedEvent::class);
        $deleted->method('getIds')->willReturn(['deleted', 'unknown', 'deleted-too']);
        $method = $entity === 'seo_url' ? 'seoDeleted' : 'visibilityDeleted';
        $subscriber->$method($deleted);
        // The snapshot is consumed, not reused by unrelated later deletions.
        $subscriber->$method($deleted);
    }

    public function testReadAndQueueFailuresDoNotBlockShopwareDeletes(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willThrowException(new \RuntimeException('Unavailable'));
        $command = $this->createMock(DeleteCommand::class);
        $command->method('getEntityName')->willReturn('seo_url');
        $command->method('getDecodedPrimaryKey')->willReturn(['id' => 'deleted']);
        $event = $this->createMock(PreWriteValidationEvent::class);
        $event->method('getCommands')->willReturn([$command]);
        $event->method('getContext')->willReturn(Context::createDefaultContext());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Unable to capture catalog deletion context', ['error' => 'Unavailable']);
        $subscriber = new CatalogDeletionSubscriber($repository, $repository, $this->createMock(MessageBusInterface::class), $logger);
        $subscriber->beforeDelete($event);
    }
}
