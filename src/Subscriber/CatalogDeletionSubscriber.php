<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CatalogDeletionSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, array<string, array{product: string, channel: ?string}>> */
    private array $snapshots = [];

    public function __construct(private EntityRepository $visibilities, private EntityRepository $seoUrls, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'beforeDelete', 'product_visibility.deleted' => 'visibilityDeleted', 'seo_url.deleted' => 'seoDeleted'];
    }

    public function reset(): void
    {
        $this->snapshots = [];
    }

    public function beforeDelete(PreWriteValidationEvent $event): void
    {
        try {
            $ids = [];
            foreach ($event->getCommands() as $command) {
                if ($command instanceof DeleteCommand && in_array($command->getEntityName(), ['product_visibility', 'seo_url'], true)) {
                    $id = $command->getDecodedPrimaryKey()['id'] ?? null;
                    if (is_string($id)) {
                        $ids[$command->getEntityName()][] = $id;
                    }
                }
            }
            foreach ($ids as $entity => $keys) {
                $criteria = new Criteria($keys);
                if ($entity === 'seo_url') {
                    $criteria->addFilter(new EqualsFilter('routeName', 'frontend.detail.page'));
                    $criteria->addFilter(new EqualsFilter('isCanonical', true));
                }
                $repository = $entity === 'seo_url' ? $this->seoUrls : $this->visibilities;
                /** @var ProductVisibilityEntity|SeoUrlEntity $row */
                foreach ($repository->search($criteria, $event->getContext()) as $row) {
                    $this->snapshots[$entity][$row->getId()] = ['product' => $row instanceof SeoUrlEntity ? $row->getForeignKey() : $row->getProductId(), 'channel' => $row->getSalesChannelId()];
                }
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to capture catalog deletion context', ['error' => $error->getMessage()]);
        }
    }

    public function visibilityDeleted(EntityDeletedEvent $event): void
    {
        $this->queueDeleted($event, 'product_visibility');
    }

    public function seoDeleted(EntityDeletedEvent $event): void
    {
        $this->queueDeleted($event, 'seo_url');
    }

    private function queueDeleted(EntityDeletedEvent $event, string $entity): void
    {
        try {
            $batches = [];
            foreach ($event->getIds() as $id) {
                $row = $this->snapshots[$entity][$id] ?? null;
                unset($this->snapshots[$entity][$id]);
                if ($row !== null) {
                    $batches[$row['channel'] ?? 'all'][$row['product']] = $row['product'];
                }
            }
            foreach ($batches as $channel => $ids) {
                $this->bus->dispatch(new SyncProductUrlsMessage(array_values($ids), $channel === 'all' ? null : $channel));
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to queue catalog deletion cleanup', ['error' => $error->getMessage()]);
        }
    }
}
