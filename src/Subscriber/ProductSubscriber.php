<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Service\ProductSyncService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
        ];
    }

    /**
     * Handle product creation/update events
     */
    public function onProductWritten(EntityWrittenEvent $event): void
    {
        // Skip if product sync is disabled
        $productSyncEnabled = $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled') ?? false;
        if (! $productSyncEnabled) {
            return;
        }

        try {
            // Verify API configuration
            $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug') ?? '';
            $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername') ?? '';
            $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey') ?? '';
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl') ?? '';

            if (empty($shopSlug) || empty($apiUsername) || empty($apiKey) || empty($apiUrl)) {
                $this->logger->warning('Product sync skipped - missing configuration', [
                    'component' => 'product.sync',
                ]);

                return;
            }

            $context = $event->getContext();
            $productIds = $event->getIds();

            $criteria = new Criteria($productIds);
            $criteria->addAssociations([
                'cover.media',
                'manufacturer',
                'categories',
                'tags',
                'translations.language.locale',
            ]);

            $products = $this->productRepository->search($criteria, $context);

            /** @var ProductEntity $product */
            foreach ($products as $product) {
                // Only sync active products
                if (! $product->getActive()) {
                    continue;
                }

                // Check if this is a parent product with variants
                if ($product->getParentId() === null && $product->getChildCount() > 0) {
                    // This is a parent - we need to sync all its variants instead
                    $this->logger->debug('Product is a parent with variants, syncing variants', [
                        'component' => 'product.sync',
                        'parent_number' => $product->getProductNumber(),
                        'child_count' => $product->getChildCount(),
                    ]);

                    // Query for all variants of this parent
                    $variantCriteria = new Criteria();
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

                    // Sync each variant with the parent entity
                    /** @var ProductEntity $variant */
                    foreach ($variants as $variant) {
                        $this->productSyncService->upsertProduct($variant, $product);

                        $this->logger->info('Variant synced to Karla', [
                            'component' => 'product.sync',
                            'variant_number' => $variant->getProductNumber(),
                            'parent_number' => $product->getProductNumber(),
                        ]);
                    }
                } else {
                    // Regular product or variant - sync it
                    $this->productSyncService->upsertProduct($product);

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
        // Skip if product sync is disabled
        $productSyncEnabled = $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled') ?? false;
        if (! $productSyncEnabled) {
            return;
        }

        try {
            // Verify API configuration
            $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug') ?? '';
            $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername') ?? '';
            $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey') ?? '';
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl') ?? '';

            if (empty($shopSlug) || empty($apiUsername) || empty($apiKey) || empty($apiUrl)) {
                $this->logger->warning('Product deletion sync skipped - missing configuration', [
                    'component' => 'product.sync',
                ]);

                return;
            }

            $productIds = $event->getIds();

            foreach ($productIds as $productId) {
                // We can't get product number from a deleted entity, use ID
                $this->productSyncService->deleteProduct($productId);

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
