<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Service\ProductSyncService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Seo\Event\SeoUrlUpdateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private EntityRepository $productRepository;
    private ProductSyncService $productSyncService;
    private SystemConfigService $systemConfigService;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $productRepository,
        ProductSyncService $productSyncService
    ) {
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->productSyncService = $productSyncService;
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onProductDeleted',
            SeoUrlUpdateEvent::class => 'onSeoUrlUpdate',
        ];
    }

    /**
     * Handle product creation/update events
     */
    public function onProductWritten(EntityWrittenEvent $event): void
    {
        try {
            foreach ($this->productSyncService->getSalesChannelIds() ?? [null] as $salesChannelId) {
                $this->processProductWritten($event, $salesChannelId);
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to resolve product sync channels', ['error' => $error->getMessage()]);
        }
    }

    private function processProductWritten(EntityWrittenEvent $event, ?string $salesChannelId): void
    {
        if ($this->isSyncConfigured($salesChannelId)) {
            // product.written precedes SEO indexing, so preserve existing URLs.
            $this->syncProducts($event->getIds(), $event->getContext(), $salesChannelId, false);
        }
    }

    public function onSeoUrlUpdate(SeoUrlUpdateEvent $event): void
    {
        try {
            $channels = $this->productSyncService->getSalesChannelIds() ?? [null];
            $batches = [];
            foreach ($event->getSeoUrls() as $row) {
                $id = $row['foreignKey'] ?? null;
                if (! is_string($id) || ! Uuid::isValid($id) || ! empty($row['error'])) {
                    continue;
                }
                // Shopware 6.7.2 serializes these events without routeName.
                if (($row['routeName'] ?? null) !== 'frontend.detail.page' && ($row['pathInfo'] ?? null) !== '/detail/' . $id) {
                    continue;
                }
                foreach ($channels as $channel) {
                    if ($channel !== null && isset($row['salesChannelId']) && $row['salesChannelId'] !== $channel) {
                        continue;
                    }
                    $batches[$channel ?? 'legacy'][$id] = $id;
                }
            }
            foreach ($batches as $channel => $ids) {
                $salesChannelId = $channel === 'legacy' ? null : $channel;
                if ($this->isSyncConfigured($salesChannelId)) {
                    $this->syncProducts(array_values($ids), Context::createDefaultContext(), $salesChannelId, true);
                }
            }
        } catch (\Throwable $error) {
            // Catalog API failures must not interrupt Shopware's SEO indexing.
            $this->logger->error('Unable to synchronize indexed product URLs', ['error' => $error->getMessage()]);
        }
    }

    private function isSyncConfigured(?string $salesChannelId): bool
    {
        // Skip if product sync is disabled
        $productSyncEnabled = $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled', $salesChannelId) ?? false;
        if (! $productSyncEnabled) {
            return false;
        }

        // Verify API configuration
        $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug', $salesChannelId) ?? '';
        $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername', $salesChannelId) ?? '';
        $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey', $salesChannelId) ?? '';
        $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId) ?? '';

        if (($salesChannelId === null && empty($shopSlug)) || empty($apiUsername) || empty($apiKey) || empty($apiUrl)) {
            $this->logger->warning('Product sync skipped - missing configuration', [
                'component' => 'product.sync',
            ]);

            return false;
        }

        return true;
    }

    /** @param list<string> $productIds */
    private function syncProducts(array $productIds, Context $context, ?string $salesChannelId, bool $resolveUrls): void
    {
        try {
            $context = clone $context;
            $context->setConsiderInheritance($salesChannelId !== null);

            $criteria = new Criteria($productIds);
            $this->productSyncService->scopeCriteria($criteria, $salesChannelId);
            $criteria->addAssociations([
                'cover.media',
                'manufacturer',
                'categories',
                'tags',
                'translations.language.locale',
            ]);

            $products = $this->productRepository->search($criteria, $context);

            /** @var array<ProductEntity> $standaloneProducts */
            $standaloneProducts = [];

            /** @var ProductEntity $product */
            foreach ($products as $product) {
                // Only sync active products
                if (! $product->getActive()) {
                    continue;
                }

                // The indexed child count can lag behind newly created variants.
                if ($product->getParentId() === null && ($product->getChildCount() > 0
                    || $this->productSyncService->hasVariants($product->getId()))) {
                    // This is a parent - we need to sync all its variants instead
                    $this->logger->debug('Product is a parent with variants, syncing variants', [
                        'component' => 'product.sync',
                        'parent_number' => $product->getProductNumber(),
                        'child_count' => $product->getChildCount(),
                    ]);

                    // Query for all variants of this parent
                    $variantCriteria = new Criteria();
                    $this->productSyncService->scopeCriteria($variantCriteria, $salesChannelId);
                    $variantCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter(
                        'parentId',
                        $product->getId()
                    ));
                    $variantCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter(
                        'active',
                        true
                    ));
                    $variantCriteria->addAssociations([
                        'cover.media',
                        'translations.language.locale',
                    ]);

                    $variants = $this->productRepository->search($variantCriteria, $context);

                    $this->logger->debug('Found variants for parent', [
                        'component' => 'product.sync',
                        'parent_number' => $product->getProductNumber(),
                        'variants_found' => $variants->count(),
                    ]);

                    // Sync all variants in one call so product URLs are resolved
                    // with a single batched SEO query instead of one per variant
                    /** @var array<ProductEntity> $variantList */
                    $variantList = iterator_to_array($variants, false);
                    $this->productSyncService->upsertProducts($variantList, $product, $salesChannelId, $resolveUrls);

                    foreach ($variantList as $variant) {
                        $this->logger->info('Variant synced to Karla', [
                            'component' => 'product.sync',
                            'variant_number' => $variant->getProductNumber(),
                            'parent_number' => $product->getProductNumber(),
                        ]);
                    }
                } else {
                    // Regular product or variant - collect for one batched sync
                    $standaloneProducts[] = $product;
                }
            }

            if (count($standaloneProducts) > 0) {
                // One call for the whole event: product URLs resolve with a
                // single batched SEO query instead of one per product
                $this->productSyncService->upsertProducts($standaloneProducts, null, $salesChannelId, $resolveUrls);

                foreach ($standaloneProducts as $product) {
                    $this->logger->info('Product synced to Karla', [
                        'component' => 'product.sync',
                        'product_number' => $product->getProductNumber(),
                        'product_name' => $product->getName(),
                    ]);
                }
            }
        } catch (\Throwable $t) {
            $this->logger->error('Unexpected error during product sync', [
                'component' => 'product.sync',
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
                'trace' => $t->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle product deletion events
     */
    public function onProductDeleted(EntityDeletedEvent $event): void
    {
        try {
            foreach ($this->productSyncService->getSalesChannelIds() ?? [null] as $salesChannelId) {
                $this->processProductDeleted($event, $salesChannelId);
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to resolve product sync channels', ['error' => $error->getMessage()]);
        }
    }

    private function processProductDeleted(EntityDeletedEvent $event, ?string $salesChannelId): void
    {
        // Skip if product sync is disabled
        $productSyncEnabled = $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled', $salesChannelId) ?? false;
        if (! $productSyncEnabled) {
            return;
        }

        try {
            // Verify API configuration
            $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug', $salesChannelId) ?? '';
            $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername', $salesChannelId) ?? '';
            $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey', $salesChannelId) ?? '';
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId) ?? '';

            if (($salesChannelId === null && empty($shopSlug)) || empty($apiUsername) || empty($apiKey) || empty($apiUrl)) {
                $this->logger->warning('Product deletion sync skipped - missing configuration', [
                    'component' => 'product.sync',
                ]);

                return;
            }

            $productIds = $event->getIds();

            foreach ($productIds as $productId) {
                // We can't get product number from a deleted entity, use ID
                $this->productSyncService->deleteProduct($productId, $salesChannelId);

                $this->logger->info('Product deleted from Karla', [
                    'component' => 'product.sync',
                    'product_id' => $productId,
                ]);
            }
        } catch (\Throwable $t) {
            $this->logger->error('Unexpected error during product deletion sync', [
                'component' => 'product.sync',
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
                'trace' => $t->getTraceAsString(),
            ]);
        }
    }
}
