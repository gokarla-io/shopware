<?php

declare(strict_types=1);

namespace Karla\Delivery\MessageHandler;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Karla\Delivery\Service\ProductSyncService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class SyncProductUrlsMessageHandler
{
    public function __construct(private ProductSyncService $products, private SystemConfigService $config)
    {
    }

    public function __invoke(SyncProductUrlsMessage $message): void
    {
        foreach ($this->products->getSalesChannelIds() ?? [null] as $channel) {
            if ($channel !== null && $message->salesChannelId !== null && $message->salesChannelId !== $channel) {
                continue;
            }
            if ($this->config->get('KarlaDelivery.config.productSyncEnabled', $channel)) {
                $this->products->syncIndexedProductIds($message->productIds, $channel);
            }
        }
    }
}
