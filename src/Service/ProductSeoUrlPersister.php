<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\SeoUrlPersister;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProductSeoUrlPersister extends SeoUrlPersister
{
    public function __construct(
        private SeoUrlPersister $inner,
        private ProductSyncService $products,
        private SystemConfigService $config,
        private MessageBusInterface $bus,
        private LoggerInterface $logger
    ) {
    }

    public function updateSeoUrls(Context $context, string $routeName, array $foreignKeys, iterable $seoUrls, SalesChannelEntity $salesChannel): void
    {
        $this->inner->updateSeoUrls($context, $routeName, $foreignKeys, $seoUrls, $salesChannel);

        try {
            $channel = $salesChannel->getId();
            if ($routeName !== 'frontend.detail.page' || $foreignKeys === []
                || ! $this->config->get('KarlaDelivery.config.productSyncEnabled', $channel)
                || $context->getLanguageId() !== ($this->products->getProductUrlLanguage($channel) ?? $salesChannel->getLanguageId())) {
                return;
            }
            // Include original IDs even when no SEO row was generated (URL removal).
            // Shared catalogs omit URLs but still need cleanup in the default channel language.
            // Only the selected storefront language queues work, avoiding language duplicates.
            $this->bus->dispatch(new SyncProductUrlsMessage(array_values(array_unique($foreignKeys)), $channel));
        } catch (\Throwable $error) {
            $this->logger->error('Unable to queue indexed product URLs', ['error' => $error->getMessage()]);
        }
    }
}
