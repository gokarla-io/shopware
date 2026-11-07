<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

class ProductSyncService implements ResetInterface
{
    /**
     * Map of parent product IDs to parent ProductEntity objects
     * Used to avoid reading parent association directly (which Shopware doesn't allow)
     * @var array<string, ProductEntity>
     */
    private array $parentMap = [];

    /**
     * Cached storefront URL base (baseUrl, salesChannelId, languageId) used to
     * build product URLs, or null when none is resolvable. Resolved once per
     * service instance.
     *
     * @var array<string, array{baseUrl: string, salesChannelId: string, languageId: string}|null>
     */
    private array $storefrontUrlBases = [];

    /**
     * @codeCoverageIgnore Simple dependency injection constructor
     */
    public function __construct(
        private EntityRepository $productRepository,
        private HttpClientInterface $httpClient,
        private SystemConfigService $systemConfigService,
        private LoggerInterface $logger,
        private Connection $connection,
        private EntityRepository $seoUrlRepository,
        private EntityRepository $salesChannelRepository
    ) {
    }

    /** @return list<string>|null Null preserves legacy unscoped catalog synchronization. */
    public function getSalesChannelIds(): ?array
    {
        if (! $this->systemConfigService->get('KarlaDelivery.config.salesChannelMapping') && ! $this->connection->fetchOne(
            "SELECT 1 FROM system_config WHERE sales_channel_id IS NOT NULL AND configuration_key IN
                ('KarlaDelivery.config.shopSlug', 'KarlaDelivery.config.productSyncEnabled') LIMIT 1",
        )) {
            return null;
        }

        return $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM sales_channel');
    }

    public function getShopSlug(?string $salesChannelId): string
    {
        $slug = (string) $this->systemConfigService->get('KarlaDelivery.config.shopSlug', $salesChannelId);
        foreach (explode(',', (string) $this->systemConfigService->get('KarlaDelivery.config.salesChannelMapping')) as $pair) {
            $mapping = array_map('trim', explode(':', $pair));
            if ($salesChannelId && count($mapping) === 2 && $mapping[0] === $salesChannelId && $mapping[1] !== '') {
                $slug = $mapping[1];
            }
        }

        return $slug;
    }

    public function scopeCriteria(Criteria $criteria, ?string $salesChannelId): void
    {
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('visibilities.salesChannelId', $salesChannelId));
        } else {
            $criteria->addFilter(new EqualsFilter('visibilities.salesChannel.active', true));
            $criteria->addFilter(new EqualsFilter('visibilities.salesChannel.typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        }
    }

    public function hasVariants(string $productId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM product WHERE parent_id = ? AND version_id = ? LIMIT 1',
            [Uuid::fromHexToBytes($productId), Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
    }

    /**
     * Clear per-instance state. Wired to `kernel.reset`, so the cached
     * storefront base and the parent map never outlive a request or a single
     * Messenger message in a long-lived worker: a storefront domain change is
     * picked up on the next message instead of the next worker restart.
     */
    public function reset(): void
    {
        $this->parentMap = [];
        $this->storefrontUrlBases = [];
    }

    public function getProductUrlLanguage(string $salesChannelId): ?string
    {
        return $this->resolveStorefrontUrlBase(Context::createDefaultContext(), $salesChannelId)['languageId'] ?? null;
    }

    /**
     * Refresh indexed products in one POST, including parents needed by child-only updates.
     * Errors propagate so the asynchronous message can be retried.
     * @param list<string> $productIds
     */
    public function syncIndexedProductIds(array $productIds, ?string $salesChannelId): void
    {
        if ($productIds === []) {
            return;
        }
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $criteria = new Criteria($productIds);
        $this->scopeCriteria($criteria, $salesChannelId);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociations(['cover.media', 'translations.language.locale']);
        $products = [];
        /** @var ProductEntity $product */
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $products[$product->getId()] = $product;
        }
        if ($products === []) {
            return;
        }

        $children = new Criteria();
        $children->addFilter(new EqualsAnyFilter('parentId', array_keys($products)));
        $children->addFilter(new EqualsFilter('active', true));
        $this->scopeCriteria($children, $salesChannelId);
        $children->addAssociations(['cover.media', 'translations.language.locale']);
        $parentsWithVariants = [];
        /** @var ProductEntity $variant */
        foreach ($this->productRepository->search($children, $context) as $variant) {
            $products[$variant->getId()] = $variant;
            $parentsWithVariants[$variant->getParentId()] = true;
        }

        $this->parentMap = [];
        $parentIds = [];
        $variants = [];
        foreach ($products as $product) {
            $parentId = $product->getParentId();
            if ($parentId === null) {
                $this->parentMap[$product->getId()] = $product;
                if ($product->getChildCount() > 0 || isset($parentsWithVariants[$product->getId()])) {
                    continue;
                }
            } else {
                $parentIds[$parentId] = $parentId;
            }
            $variants[] = $product;
        }
        $missingParents = array_values(array_diff($parentIds, array_keys($this->parentMap)));
        if ($missingParents !== []) {
            $parentCriteria = new Criteria($missingParents);
            $parentCriteria->addAssociations(['cover.media']);
            /** @var ProductEntity $parent */
            foreach ($this->productRepository->search($parentCriteria, $context) as $parent) {
                $this->parentMap[$parent->getId()] = $parent;
            }
        }
        if ($variants !== []) {
            $this->bulkUpsertProducts($variants, $salesChannelId);
        }
    }

    /**
     * Sync a batch of products using bulk API
     * Fetches products from DB and sends them in a single bulk API request
     * Returns true if there are more products to sync
     */
    public function syncProductBatch(int $offset, int $limit, ?string $salesChannelId = null): bool
    {
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $this->parentMap = [];

        $criteria = new Criteria();
        $criteria->setOffset($offset);
        $criteria->setLimit($limit);
        $this->scopeCriteria($criteria, $salesChannelId);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        // Only sync active products
        $criteria->addFilter(new EqualsFilter('active', true));

        // Load associations
        $criteria->addAssociations([
            'cover.media',
            'translations.language.locale',  // Need locale for translation language codes
        ]);

        // Fetch products - Shopware returns parent products by default
        $productsResult = $this->productRepository->search($criteria, $context);

        if ($productsResult->count() === 0) {
            return false;
        }

        // Convert to array so we can iterate multiple times
        /** @var array<ProductEntity> $products */
        $products = $productsResult->getElements();

        // Now query for ALL variants separately
        // We need to get variants where parentId is one of the parent product IDs
        $parentIds = [];
        $productDebug = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $parentId = $product->getParentId();
            $childCount = $product->getChildCount();

            $productDebug[] = [
                'number' => $product->getProductNumber(),
                'id' => $product->getId(),
                'parent_id' => $parentId,
                'child_count' => $childCount,
                'has_parent' => $parentId !== null,
                'is_parent' => $parentId === null && $childCount > 0,
            ];

            if ($parentId === null && $childCount > 0) {
                $parentIds[] = $product->getId();
            }
        }

        $this->logger->debug('Analyzing products for variants', [
            'component' => 'product.bulk_sync',
            'total_products' => count($products),
            'parent_ids_found' => count($parentIds),
            'products' => $productDebug,
        ]);

        $allVariants = [];
        if (count($parentIds) > 0) {
            // Shopware's repository blocks variant queries, so we query variant IDs directly from DB
            // then load them using Criteria (which Shopware allows for specific IDs)

            // Convert parent IDs to binary for SQL query (remove dashes first)
            $parentIdsBinary = array_map(fn ($id) => hex2bin(str_replace('-', '', $id)), $parentIds);

            // First check what version_id the parents have
            $parentCheck = 'SELECT LOWER(HEX(id)) as id, product_number, HEX(version_id) as version_id
                           FROM product
                           WHERE id IN (?)';
            $parentInfo = $this->connection->fetchAllAssociative(
                $parentCheck,
                [$parentIdsBinary],
                [\Doctrine\DBAL\ArrayParameterType::BINARY]
            );

            $this->logger->debug('Querying variant IDs from database', [
                'component' => 'product.bulk_sync',
                'parent_ids' => $parentIds,
                'parent_count' => count($parentIds),
                'parent_version_info' => $parentInfo,
            ]);

            // Debug: Check if variants exist at all
            $sqlAll = 'SELECT LOWER(HEX(id)) as id, product_number, active, HEX(version_id) as version_id
                       FROM product
                       WHERE parent_id IN (?)
                       LIMIT 20';

            $allVariantRows = $this->connection->fetchAllAssociative(
                $sqlAll,
                [$parentIdsBinary],
                [\Doctrine\DBAL\ArrayParameterType::BINARY]
            );

            $this->logger->debug('All variants in database (any version)', [
                'component' => 'product.bulk_sync',
                'all_variants_found' => count($allVariantRows),
                'sample_variants' => array_slice($allVariantRows, 0, 5),
            ]);

            // Query variant IDs directly from database
            // NOTE: Variants inherit active status from parent, so active column is NULL for variants
            $sql = 'SELECT LOWER(HEX(id)) as id, product_number
                    FROM product
                    WHERE parent_id IN (?) AND version_id = 0x0FA91CE3E96A4BC2BE4BD9CE752C3425';

            $variantRows = $this->connection->fetchAllAssociative(
                $sql,
                [$parentIdsBinary],
                [\Doctrine\DBAL\ArrayParameterType::BINARY]
            );

            $this->logger->debug('Active variants retrieved from database', [
                'component' => 'product.bulk_sync',
                'variants_found' => count($variantRows),
            ]);

            if (count($variantRows) > 0) {
                // Now load the full ProductEntity objects using their IDs
                $variantIds = array_column($variantRows, 'id');

                $variantCriteria = new Criteria($variantIds);
                $this->scopeCriteria($variantCriteria, $salesChannelId);
                $variantCriteria->addFilter(new EqualsFilter('active', true));
                $variantCriteria->addAssociations([
                    'cover.media',
                    'translations.language.locale',
                ]);

                $variantsResult = $this->productRepository->search($variantCriteria, $context);

                $this->logger->debug('Variant entities loaded', [
                    'component' => 'product.bulk_sync',
                    'variants_loaded' => $variantsResult->count(),
                ]);

                // Collect variants
                $variantDetails = [];
                /** @var ProductEntity $variant */
                foreach ($variantsResult as $variant) {
                    $allVariants[] = $variant;
                    $variantDetails[] = [
                        'number' => $variant->getProductNumber(),
                        'id' => $variant->getId(),
                        'parent_id' => $variant->getParentId(),
                    ];
                }

                $this->logger->debug('Variants retrieved', [
                    'component' => 'product.bulk_sync',
                    'variants' => $variantDetails,
                ]);

                // Load parent products separately (Shopware doesn't allow reading parent association directly)
                $parentIds = array_unique(array_filter(array_map(fn ($v) => $v->getParentId(), $allVariants)));
                if (count($parentIds) > 0) {
                    $parentCriteria = new Criteria($parentIds);
                    $parentCriteria->addAssociations([
                        'cover.media',
                    ]);

                    $parentsResult = $this->productRepository->search($parentCriteria, $context);

                    // Create lookup map: parent ID => parent entity
                    $parentMap = [];
                    /** @var ProductEntity $parent */
                    foreach ($parentsResult as $parent) {
                        $parentMap[$parent->getId()] = $parent;
                    }

                    $this->logger->debug('Parent products loaded separately', [
                        'component' => 'product.bulk_sync',
                        'parents_loaded' => count($parentMap),
                    ]);

                    // Store parent map for use in buildVariantPayload
                    $this->parentMap = $parentMap;
                }
            }
        } else {
            $this->logger->warning('No parent products found with variants', [
                'component' => 'product.bulk_sync',
                'total_products' => count($products),
            ]);
        }

        // Process products - separate parents from standalone products
        $allProducts = [];
        $productDetails = [];

        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $parentId = $product->getParentId();
            $childCount = $product->getChildCount();
            $isParentWithVariants = $parentId === null && $childCount > 0;

            if ($isParentWithVariants) {
                // Log parent (but don't add to sync list - we'll add variants instead)
                $productDetails[] = [
                    'number' => $product->getProductNumber(),
                    'active' => $product->getActive(),
                    'parent_id' => null,
                    'child_count' => $childCount,
                    'type' => 'PARENT',
                ];
            } else {
                // Standalone product (no variants) - add to sync list
                $allProducts[] = $product;
                $productDetails[] = [
                    'number' => $product->getProductNumber(),
                    'active' => $product->getActive(),
                    'parent_id' => $parentId,
                    'child_count' => $childCount,
                    'type' => 'STANDALONE',
                ];
            }
        }

        // Add all variants we fetched separately
        /** @var ProductEntity $variant */
        foreach ($allVariants as $variant) {
            $allProducts[] = $variant;
            $productDetails[] = [
                'number' => $variant->getProductNumber(),
                'active' => $variant->getActive(),
                'parent_id' => $variant->getParentId(),
                'child_count' => 0,
                'type' => 'VARIANT',
            ];
        }

        // A page can contain a child before its parent's indexed child count is current.
        // Retain root parents and load any missing ones before building child payloads.
        foreach ($products as $product) {
            if ($product->getParentId() === null) {
                $this->parentMap[$product->getId()] = $product;
            }
        }
        $leafParents = [];
        foreach ($allProducts as $product) {
            if ($product->getParentId() !== null) {
                $leafParents[$product->getParentId()] = $product->getParentId();
            }
        }
        $missingParents = array_values(array_diff($leafParents, array_keys($this->parentMap)));
        if ($missingParents !== []) {
            $parentCriteria = new Criteria($missingParents);
            $parentCriteria->addAssociations(['cover.media']);
            /** @var ProductEntity $parent */
            foreach ($this->productRepository->search($parentCriteria, $context) as $parent) {
                $this->parentMap[$parent->getId()] = $parent;
            }
        }
        $productsToSync = [];
        foreach ($allProducts as $product) {
            if (! isset($leafParents[$product->getId()])) {
                $productsToSync[$product->getId()] = $product;
            }
        }
        $productsToSync = array_values($productsToSync);

        // Debug: Check if we're getting variants
        $variantCount = 0;
        $parentCount = 0;
        $standaloneCount = 0;
        foreach ($productDetails as $detail) {
            if ($detail['type'] === 'VARIANT') {
                $variantCount++;
            } elseif ($detail['type'] === 'PARENT') {
                $parentCount++;
            } else {
                $standaloneCount++;
            }
        }

        $this->logger->info('Syncing product batch', [
            'component' => 'product.bulk_sync',
            'offset' => $offset,
            'count' => $productsResult->count(),
            'total' => $productsResult->getTotal(),
            'products_to_sync' => count($productsToSync),
            'variants_found' => $variantCount,
            'parents_found' => $parentCount,
            'standalone_found' => $standaloneCount,
            'products' => $productDetails,
        ]);

        if (count($productsToSync) === 0) {
            // No products to sync in this batch, but continue to next batch
            return ($offset + $limit) < $productsResult->getTotal();
        }

        /** @var array<ProductEntity> $productArray */
        $productArray = $productsToSync;

        try {
            $this->bulkUpsertProducts($productArray, $salesChannelId);
        } catch (\Throwable $t) {
            // Log error but continue
            $this->logger->error('Failed to sync product batch', [
                'component' => 'product.bulk_sync',
                'offset' => $offset,
                'count' => count($productArray),
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
            ]);
        }

        // Return true if there are more products to process
        return ($offset + $limit) < $productsResult->getTotal();
    }

    /**
     * Upsert a single product to Karla (real-time sync)
     * Uses individual PUT endpoint for precision and better error handling
     * Never throws - errors are logged only to prevent blocking Shopware operations
     *
     * @param ProductEntity $product The product/variant to sync
     * @param ProductEntity|null $parent Optional parent product (if syncing a variant)
     */
    public function upsertProduct(ProductEntity $product, ?ProductEntity $parent = null, ?string $salesChannelId = null): void
    {
        try {
            // Resolve canonical product URL (variant's own SEO URL, else parent's)
            $urlMap = $this->resolveProductUrls([$product->getId(), $product->getParentId()], $salesChannelId);
        } catch (\Throwable) {
            // Entity getters can throw on partially hydrated entities; keep the
            // never-throws contract and degrade to "resolution unavailable"
            $urlMap = null;
        }

        $this->upsertProductWithUrlMap($product, $parent, $urlMap, $salesChannelId);
    }

    /**
     * Upsert several products/variants in one call, resolving all product URLs
     * with a single batched SEO query (avoids N+1 queries on multi-variant writes).
     * Never throws - errors are logged only to prevent blocking Shopware operations
     *
     * @param array<ProductEntity> $products
     * @param ProductEntity|null $parent Optional shared parent (when syncing variants)
     * @param bool $resolveUrls False before SEO indexing, to preserve stored URLs
     */
    public function upsertProducts(array $products, ?ProductEntity $parent = null, ?string $salesChannelId = null, bool $resolveUrls = true): void
    {
        if (count($products) === 0) {
            return;
        }

        try {
            $idsForUrls = [];
            foreach ($products as $product) {
                $idsForUrls[] = $product->getId();
                $idsForUrls[] = $product->getParentId();
            }
            if ($parent !== null) {
                $idsForUrls[] = $parent->getId();
            }
            $urlMap = $resolveUrls ? $this->resolveProductUrls($idsForUrls, $salesChannelId) : null;
        } catch (\Throwable) {
            // Entity getters can throw on partially hydrated entities; keep the
            // never-throws contract and degrade to "resolution unavailable"
            $urlMap = null;
        }

        foreach ($products as $product) {
            $this->upsertProductWithUrlMap($product, $parent, $urlMap, $salesChannelId);
        }
    }

    /**
     * @param array<string, string>|null $urlMap Resolved URL map, or null when
     *        resolution was unavailable (product_url omitted from the payload)
     */
    private function upsertProductWithUrlMap(
        ProductEntity $product,
        ?ProductEntity $parent,
        ?array $urlMap,
        ?string $salesChannelId
    ): void {
        try {
            $shopSlug = $this->getShopSlug($salesChannelId);
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId);
            $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId) ?? false;

            // Validate required config
            if (empty($shopSlug) || empty($apiUrl)) {
                throw new \RuntimeException('Missing required config (shopSlug or apiUrl)');
            }

            // If a parent was provided, store it in the map for buildVariantPayload to use
            if ($parent !== null) {
                $this->parentMap[$parent->getId()] = $parent;
            }

            // Build variant payload
            $variantPayload = $this->buildVariantPayload($product, $urlMap);

            $karlaProductId = $variantPayload['product_id'];
            $karlaVariantId = $variantPayload['variant_id'];

            if ($debugMode) {
                $this->logger->debug('Upserting product to Karla', [
                    'component' => 'product.sync',
                    'product_number' => $product->getProductNumber(),
                    'product_id' => $karlaProductId,
                    'variant_id' => $karlaVariantId,
                ]);
            }

            // Individual PUT endpoint: /shops/{slug}/products/{product_id}/variants/{variant_id}
            $url = sprintf(
                '%s/v1/shops/%s/products/%s/variants/%s',
                $apiUrl,
                urlencode($shopSlug),
                urlencode($karlaProductId),
                urlencode($karlaVariantId)
            );

            // Remove IDs from payload (they're in the URL)
            unset($variantPayload['product_id'], $variantPayload['variant_id']);

            $this->sendRequestToKarlaApi($url, 'PUT', $variantPayload, $salesChannelId);
        } catch (\Throwable $t) {
            // NEVER let exceptions escape - log only to prevent blocking Shopware operations
            $this->logger->error('Failed to upsert product to Karla', [
                'component' => 'product.sync',
                'product_id' => $product->getId(),
                'product_number' => $product->getProductNumber(),
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
            ]);
        }
    }

    /**
     * Bulk upsert products to Karla (batch sync)
     * Uses bulk POST endpoint for efficiency
     *
     * @param ProductEntity[] $products
     */
    private function bulkUpsertProducts(array $products, ?string $salesChannelId): void
    {
        $shopSlug = $this->getShopSlug($salesChannelId);
        $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId);
        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId) ?? false;

        // Validate required config
        if (empty($shopSlug) || empty($apiUrl)) {
            throw new \RuntimeException('Missing required config (shopSlug or apiUrl)');
        }

        // Resolve canonical product URLs for the whole batch in one query.
        // Collect both the variant's own ID and its parent ID, since a variant's
        // SEO URL may live under either.
        $idsForUrls = [];
        foreach ($products as $product) {
            $idsForUrls[] = $product->getId();
            $idsForUrls[] = $product->getParentId();
        }
        $urlMap = $this->resolveProductUrls($idsForUrls, $salesChannelId);

        // Build array of variant payloads
        // buildVariantPayload() is fully defensive and cannot throw
        $variantPayloads = [];
        foreach ($products as $product) {
            $variantPayloads[] = $this->buildVariantPayload($product, $urlMap);
        }

        if ($debugMode) {
            $this->logger->debug('Bulk upserting products to Karla', [
                'component' => 'product.bulk_sync',
                'count' => count($variantPayloads),
            ]);
        }

        // Bulk POST endpoint: /shops/{slug}/products
        $url = $apiUrl . '/v1/shops/' . urlencode($shopSlug) . '/products';
        $this->sendRequestToKarlaApi($url, 'POST', $variantPayloads, $salesChannelId);
    }

    /**
     * Resolve canonical storefront product URLs for the given product IDs.
     *
     * Returns a map of product/variant ID => absolute product URL when
     * resolution succeeded; IDs without a canonical SEO URL are simply absent,
     * so callers send product_url: null (the product genuinely has no URL).
     * Returns NULL when resolution is unavailable (no storefront, query error):
     * callers then omit product_url entirely, so a degraded sync can never
     * erase URLs already stored in Karla. Fully defensive: never throws.
     *
     * @param array<int, string|null> $productIds
     * @return array<string, string>|null
     */
    private function resolveProductUrls(array $productIds, ?string $salesChannelId): ?array
    {
        $ids = array_values(array_unique(array_filter($productIds)));
        if (count($ids) === 0) {
            // No usable IDs: resolution is unavailable, not "resolved to nothing"
            return null;
        }

        try {
            $context = Context::createDefaultContext();

            $base = $this->resolveStorefrontUrlBase($context, $salesChannelId);
            if ($base === null) {
                return null;
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('routeName', 'frontend.detail.page'));
            // Match channel-specific rows AND "all sales channels" rows: admin-edited
            // SEO paths applied to every channel are stored with a NULL salesChannelId
            // (mirrors core SeoResolver's `sales_channel_id = ? OR IS NULL`).
            $criteria->addFilter(new OrFilter([
                new EqualsFilter('salesChannelId', $base['salesChannelId']),
                new EqualsFilter('salesChannelId', null),
            ]));
            $criteria->addFilter(new EqualsFilter('languageId', $base['languageId']));
            $criteria->addFilter(new EqualsFilter('isCanonical', true));
            $criteria->addFilter(new EqualsFilter('isDeleted', false));
            $criteria->addFilter(new EqualsAnyFilter('foreignKey', $ids));

            $urls = [];
            /** @var SeoUrlEntity $seoUrl */
            foreach ($this->seoUrlRepository->search($criteria, $context)->getEntities() as $seoUrl) {
                if ($seoUrl->getSalesChannelId() !== null && $seoUrl->getSalesChannelId() !== $base['salesChannelId']) {
                    continue;
                }
                $path = ltrim((string) $seoUrl->getSeoPathInfo(), '/');
                if ($path === '') {
                    continue;
                }
                $foreignKey = $seoUrl->getForeignKey();
                // Prefer the channel-specific row when both exist (core behaviour)
                if (isset($urls[$foreignKey]) && $seoUrl->getSalesChannelId() === null) {
                    continue;
                }
                $urls[$foreignKey] = $base['baseUrl'] . '/' . $path;
            }

            return $urls;
        } catch (\Throwable $t) {
            $this->logger->warning('Failed to resolve product URLs; omitting product_url', [
                'component' => 'product.sync',
                'error' => $t->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Auto-detect the storefront sales channel to build product URLs against.
     *
     * Picks the active sales channel that has a configured domain (a storefront).
     * With a single storefront — the common case — this needs no configuration.
     * With several, an explicit sales channel is required to avoid cross-brand
     * URLs. The chosen domain prefers the channel's default language. Results
     * are cached separately per sales channel for the service instance.
     *
     * @return array{baseUrl: string, salesChannelId: string, languageId: string}|null
     */
    private function resolveStorefrontUrlBase(Context $context, ?string $salesChannelId): ?array
    {
        $cacheKey = $salesChannelId ?? 'legacy';
        if (array_key_exists($cacheKey, $this->storefrontUrlBases)) {
            return $this->storefrontUrlBases[$cacheKey];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        // Only storefront channels get product SEO URLs generated. API/headless
        // and comparison-feed channels with a configured domain must never win
        // the pick: their SEO lookup would silently null every product_url.
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $criteria->addAssociation('domains');

        /** @var array<SalesChannelEntity> $channels */
        $channels = $this->salesChannelRepository->search($criteria, $context)->getEntities()->getElements();

        // Mark resolved only after a successful query: a transient failure throws
        // to the caller and is retried on the next call, instead of being cached
        // as "no storefront" for the lifetime of the service instance.
        // A storefront is an active channel with a usable domain. Key by channel
        // ID so ambiguous legacy configurations can be detected.
        $candidates = [];
        $destinationSlug = $salesChannelId !== null ? $this->getShopSlug($salesChannelId) : null;
        $destinationChannels = [];
        $destinationApiUrl = rtrim((string) $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId), '/');
        foreach ($channels as $channel) {
            $base = $this->buildChannelUrlBase($channel);
            if ($base !== null) {
                $channelId = $channel->getId();
                if ($salesChannelId === null || $channelId === $salesChannelId) {
                    $candidates[$channelId] = $base;
                }
                if ($salesChannelId !== null && $this->getShopSlug($channelId) === $destinationSlug
                    && rtrim((string) $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $channelId), '/') === $destinationApiUrl
                    && ($channelId === $salesChannelId || $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled', $channelId))) {
                    $destinationChannels[] = $channelId;
                }
            }
        }

        if (count($destinationChannels) > 1) {
            $this->logger->warning('Multiple storefronts sync to the same Karla shop; product_url will be omitted', [
                'component' => 'product.sync',
                'shop_slug' => $destinationSlug,
                'sales_channel_ids' => $destinationChannels,
            ]);

            return $this->storefrontUrlBases[$cacheKey] = null;
        }

        if (count($candidates) === 0) {
            $this->logger->info('No storefront sales channel with a domain found; product_url will be omitted', [
                'component' => 'product.sync',
            ]);

            return $this->storefrontUrlBases[$cacheKey] = null;
        }

        if (count($candidates) > 1) {
            $this->logger->warning('Product URL resolution needs an explicit sales channel when multiple storefronts exist', [
                'component' => 'product.sync',
                'sales_channel_ids' => array_keys($candidates),
            ]);

            return $this->storefrontUrlBases[$cacheKey] = null;
        }

        /** @var array{baseUrl: string, salesChannelId: string, languageId: string} $first */
        $first = reset($candidates);

        return $this->storefrontUrlBases[$cacheKey] = $first;
    }

    /**
     * Build the URL base for one sales channel, or null when it has no usable
     * domain. Prefers the domain matching the channel's default language.
     *
     * @return array{baseUrl: string, salesChannelId: string, languageId: string}|null
     */
    private function buildChannelUrlBase(SalesChannelEntity $channel): ?array
    {
        $domains = $channel->getDomains();
        if ($domains === null) {
            return null;
        }

        $chosen = null;
        $chosenRank = null;
        foreach ($domains as $domain) {
            $baseUrl = rtrim((string) $domain->getUrl(), '/');
            if ($baseUrl === '') {
                continue;
            }
            // Default language first, HTTPS next, then a stable URL ordering.
            $rank = [$domain->getLanguageId() !== $channel->getLanguageId(), ! str_starts_with(strtolower($baseUrl), 'https://'), $baseUrl, $domain->getLanguageId()];
            if ($chosenRank === null || $rank < $chosenRank) {
                $chosenRank = $rank;
                $chosen = [
                    'baseUrl' => $baseUrl,
                    'salesChannelId' => $channel->getId(),
                    'languageId' => $domain->getLanguageId(),
                ];
            }
        }

        return $chosen;
    }

    /**
     * Pick the resolved URL for a product: prefer the variant's own SEO URL,
     * fall back to its parent's.
     *
     * @param array<string, string> $urlMap
     */
    private function lookupProductUrl(array $urlMap, ProductEntity $product): ?string
    {
        $parentId = $product->getParentId();

        return $urlMap[$product->getId()]
            ?? ($parentId !== null ? ($urlMap[$parentId] ?? null) : null);
    }

    /**
     * Build product variant payload for Karla API
     *
     * @param array<string, string>|null $urlMap Resolved product URLs, or null
     *        when resolution was unavailable (product_url omitted entirely)
     */
    private function buildVariantPayload(ProductEntity $product, ?array $urlMap): array
    {
        // Determine product_id and variant_id
        // For Shopware: parent ID is the container, product ID is the variant
        $parentId = $product->getParentId();
        $productId = $product->getId();

        $karlaProductId = $parentId ?? $productId;  // Use parent if exists, else self
        $karlaVariantId = $productId;  // The actual variant/product ID

        // Get parent product if this is a variant (from our pre-loaded map)
        $parent = $parentId && isset($this->parentMap[$parentId]) ? $this->parentMap[$parentId] : null;

        // For variants: title = parent name, variant_title = variant name
        // For standalone products: title = product name, variant_title = null
        $variantName = $product->getName();
        if (empty($variantName)) {
            $variantName = $product->getProductNumber() ?: 'Unknown Product';
        }

        $parentName = null;
        if ($parent) {
            $parentName = $parent->getName();
            if (empty($parentName)) {
                $parentName = $parent->getProductNumber() ?: 'Unknown Product';
            }
        }

        // Determine price: try variant price first, then parent price
        $price = null;
        if ($product->getPrice()) {
            $price = $product->getPrice()->first()?->getGross();
        } elseif ($parent && $parent->getPrice()) {
            $price = $parent->getPrice()->first()?->getGross();
        }

        // Build base payload
        $payload = [
            'product_id' => $karlaProductId,
            'variant_id' => $karlaVariantId,
            'title' => $parentId ? $parentName : $variantName,  // Parent name for variants, own name for standalone
            'variant_title' => $parentId ? $variantName : null,  // Variant name if it's a variant, null otherwise
            'price' => $price,
            'sku' => $product->getProductNumber(),
        ];

        // product_url: resolved canonical storefront SEO URL. When the URL map is
        // null, resolution was unavailable — omit the key entirely so a degraded
        // sync can never erase a URL already stored in Karla (an explicit null
        // clears it; absence means "no change").
        if ($urlMap !== null) {
            $payload['product_url'] = $this->lookupProductUrl($urlMap, $product);
        }

        // Add cover image if available
        // Try variant's own cover first, then fallback to parent's cover
        $cover = $product->getCover();
        if ($cover && $cover->getMedia()) {
            $payload['image_url'] = $cover->getMedia()->getUrl();
        } elseif ($parent) {
            // Variant has no image, try to use parent's image
            $parentCover = $parent->getCover();
            if ($parentCover && $parentCover->getMedia()) {
                $payload['image_url'] = $parentCover->getMedia()->getUrl();
            }
        }

        // Add translations if available
        $translations = $this->buildTranslations($product);
        if (! empty($translations)) {
            $payload['translations'] = $translations;
        }

        return $payload;
    }

    /**
     * Build translations object from product translations
     * Converts Shopware locale codes (e.g., de-DE) to Karla language codes (e.g., de)
     */
    private function buildTranslations(ProductEntity $product): array
    {
        $translations = [];

        $productTranslations = $product->getTranslations();
        if (! $productTranslations) {
            return $translations;
        }

        foreach ($productTranslations as $translation) {
            // Get language and locale
            $language = $translation->getLanguage();
            if (! $language) {
                continue;
            }

            $locale = $language->getLocale();
            if (! $locale) {
                continue;
            }

            // Extract language code (first 2 chars of locale code: de-DE -> de)
            $localeCode = $locale->getCode();
            if (empty($localeCode) || strlen($localeCode) < 2) {
                continue;
            }
            $languageCode = substr($localeCode, 0, 2);

            // Build translation object
            $translationData = [];
            if ($translation->getName()) {
                $translationData['title'] = $translation->getName();
            }

            // Only add if we have translation data
            if (! empty($translationData)) {
                $translations[$languageCode] = $translationData;
            }
        }

        return $translations;
    }

    /**
     * Delete product from Karla by product ID
     * This uses cascade delete - removes product and all its variants
     * Never throws - errors are logged only to prevent blocking Shopware operations
     */
    public function deleteProduct(string $productId, ?string $salesChannelId = null): void
    {
        try {
            $shopSlug = $this->getShopSlug($salesChannelId);
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl', $salesChannelId);
            $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId) ?? false;

            // Validate required config
            if (empty($shopSlug) || empty($apiUrl)) {
                throw new \RuntimeException('Missing required config (shopSlug or apiUrl)');
            }

            if ($debugMode) {
                $this->logger->debug('Deleting product from Karla', [
                    'component' => 'product.sync',
                    'product_id' => $productId,
                ]);
            }

            // Cascade delete endpoint: DELETE /shops/{slug}/products/{product_id}
            $url = sprintf(
                '%s/v1/shops/%s/products/%s',
                $apiUrl,
                urlencode($shopSlug),
                urlencode($productId)
            );

            $this->sendRequestToKarlaApi($url, 'DELETE', [], $salesChannelId);
        } catch (\Throwable $t) {
            // NEVER let exceptions escape - log only to prevent blocking Shopware operations
            $this->logger->error('Failed to delete product from Karla', [
                'component' => 'product.sync',
                'product_id' => $productId,
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
            ]);
        }
    }

    /**
     * Send request to Karla's API
     *
     * @throws \RuntimeException if config is invalid or JSON encoding fails
     */
    private function sendRequestToKarlaApi(string $url, string $method, array $data, ?string $salesChannelId): void
    {
        (new CatalogApiClient($this->httpClient, $this->systemConfigService, $this->logger))->request($url, $method, $data, $salesChannelId);
    }
}
