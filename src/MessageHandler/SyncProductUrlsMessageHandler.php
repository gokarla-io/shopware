<?php

declare(strict_types=1);

namespace Karla\Delivery\MessageHandler;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Karla\Delivery\Service\CatalogCleanupService;
use Karla\Delivery\Service\ProductSyncService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncProductUrlsMessageHandler
{
    public function __construct(private ProductSyncService $products, private SystemConfigService $config, private CatalogCleanupService $cleanup, private MessageBusInterface $bus)
    {
    }

    public function __invoke(SyncProductUrlsMessage $message): void
    {
        foreach ($this->products->getSalesChannelIds() ?? [null] as $channel) {
            if ($channel !== null && $message->salesChannelId !== null && $message->salesChannelId !== $channel) {
                continue;
            }
            if ($this->config->get('KarlaDelivery.config.productSyncEnabled', $channel)) {
                $this->cleanup->withCatalogLock($channel, function () use ($message, $channel): void {
                    if ($message->reconcile) {
                        // Keep other cleanup jobs from shifting offset pages during the snapshot.
                        foreach (array_chunk($this->cleanup->catalogVariantIds($channel), 100) as $ids) {
                            $this->bus->dispatch(new SyncProductUrlsMessage($ids, $channel));
                        }
                    } else {
                        $ids = $this->cleanup->retireUnavailable($message->productIds, $channel);
                        if ($message->refreshUrls && $ids !== []) {
                            $this->products->syncIndexedProductIds($ids, $channel);
                        }
                    }
                });
            }
        }
    }
}
