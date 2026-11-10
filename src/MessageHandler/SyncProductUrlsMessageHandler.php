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
        $channels = $this->products->getSalesChannelIds();
        if ($message->salesChannelId === null && $channels !== null) {
            // Each destination owns its retries; an unavailable API cannot starve another shop.
            foreach ($channels as $channel) {
                if ($this->isConfigured($channel)) {
                    $this->bus->dispatch(new SyncProductUrlsMessage($message->productIds, $channel, $message->reconcile, $message->refreshUrls));
                }
            }

            return;
        }
        $channel = $channels === null ? null : $message->salesChannelId;
        if (($channels !== null && ! in_array($channel, $channels, true)) || ! $this->isConfigured($channel)) {
            return;
        }
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

    private function isConfigured(?string $channel): bool
    {
        return (bool) $this->config->get('KarlaDelivery.config.productSyncEnabled', $channel)
            && $this->products->getShopSlug($channel) !== ''
            && ! empty($this->config->get('KarlaDelivery.config.apiUrl', $channel))
            && ! empty($this->config->get('KarlaDelivery.config.apiUsername', $channel))
            && ! empty($this->config->get('KarlaDelivery.config.apiKey', $channel));
    }
}
