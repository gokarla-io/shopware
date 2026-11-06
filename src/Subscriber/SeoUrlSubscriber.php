<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class SeoUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityRepository $seoUrls, private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['seo_url.written' => 'onSeoUrlWritten'];
    }

    public function onSeoUrlWritten(EntityWrittenEvent $event): void
    {
        try {
            $ids = $event->getIds();
            if ($ids === []) {
                return;
            }
            $criteria = new Criteria($ids);
            $criteria->addFilter(new EqualsFilter('routeName', 'frontend.detail.page'));
            $batches = [];
            /** @var SeoUrlEntity $row */
            foreach ($this->seoUrls->search($criteria, $event->getContext()) as $row) {
                $batches[$row->getSalesChannelId() ?? 'all'][$row->getForeignKey()] = $row->getForeignKey();
            }
            foreach ($batches as $channel => $ids) {
                $this->bus->dispatch(new SyncProductUrlsMessage(array_values($ids), $channel === 'all' ? null : $channel));
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to queue edited product SEO URLs', ['error' => $error->getMessage()]);
        }
    }
}
