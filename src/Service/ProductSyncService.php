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
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProductSyncService
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
     * @var array{baseUrl: string, salesChannelId: string, languageId: string}|null
     */
    private ?array $storefrontUrlBase = null;

    private bool $storefrontResolved = false;

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

    /**
     * Sync a batch of products using bulk API
     * Fetches products from DB and sends them in a single bulk API request
     * Returns true if there are more products to sync
     */
    public function syncProductBatch(int $offset, int $limit): bool
    {
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->setOffset($offset);
        $criteria->setLimit($limit);

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

        $productsToSync = $allProducts;

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
            $this->bulkUpsertProducts($productArray);
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
    public function upsertProduct(ProductEntity $product, ?ProductEntity $parent = null): void
    {
        try {
            // Resolve canonical product URL (variant's own SEO URL, else parent's)
            $urlMap = $this->resolveProductUrls([$product->getId(), $product->getParentId()]);
        } catch (\Throwable) {
            // Entity getters can throw on partially hydrated entities; keep the
            // never-throws contract and degrade to "resolution unavailable"
            $urlMap = null;
        }

        $this->upsertProductWithUrlMap($product, $parent, $urlMap);
    }

    /**
     * Upsert several products/variants in one call, resolving all product URLs
     * with a single batched SEO query (avoids N+1 queries on multi-variant writes).
     * Never throws - errors are logged only to prevent blocking Shopware operations
     *
     * @param array<ProductEntity> $products
     * @param ProductEntity|null $parent Optional shared parent (when syncing variants)
     */
    public function upsertProducts(array $products, ?ProductEntity $parent = null): void
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
            $urlMap = $this->resolveProductUrls($idsForUrls);
        } catch (\Throwable) {
            // Entity getters can throw on partially hydrated entities; keep the
            // never-throws contract and degrade to "resolution unavailable"
            $urlMap = null;
        }

        foreach ($products as $product) {
            $this->upsertProductWithUrlMap($product, $parent, $urlMap);
        }
    }

    /**
     * @param array<string, string>|null $urlMap Resolved URL map, or null when
     *        resolution was unavailable (product_url omitted from the payload)
     */
    private function upsertProductWithUrlMap(
        ProductEntity $product,
        ?ProductEntity $parent,
        ?array $urlMap
    ): void {
        try {
            $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug');
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl');
            $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode') ?? false;

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

            $this->sendRequestToKarlaApi($url, 'PUT', $variantPayload);
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
    private function bulkUpsertProducts(array $products): void
    {
        $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug');
        $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl');
        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode') ?? false;

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
        $urlMap = $this->resolveProductUrls($idsForUrls);

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
        $this->sendRequestToKarlaApi($url, 'POST', $variantPayloads);
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
    private function resolveProductUrls(array $productIds): ?array
    {
        $ids = array_values(array_unique(array_filter($productIds)));
        if (count($ids) === 0) {
            // No usable IDs: resolution is unavailable, not "resolved to nothing"
            return null;
        }

        try {
            $context = Context::createDefaultContext();

            $base = $this->resolveStorefrontUrlBase($context);
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
     * With several it picks deterministically (lowest ID) and logs a warning, so
     * a mis-pick is visible rather than silent. The chosen domain prefers the
     * channel's default language. Result is cached for the service instance.
     *
     * @return array{baseUrl: string, salesChannelId: string, languageId: string}|null
     */
    private function resolveStorefrontUrlBase(Context $context): ?array
    {
        if ($this->storefrontResolved) {
            return $this->storefrontUrlBase;
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
        $this->storefrontResolved = true;

        // A storefront is an active channel with a usable domain. Key by channel
        // ID so the pick is deterministic across runs.
        $candidates = [];
        foreach ($channels as $channel) {
            $base = $this->buildChannelUrlBase($channel);
            if ($base !== null) {
                $candidates[$channel->getId()] = $base;
            }
        }

        if (count($candidates) === 0) {
            $this->logger->info('No storefront sales channel with a domain found; product_url will be omitted', [
                'component' => 'product.sync',
            ]);

            return $this->storefrontUrlBase = null;
        }

        ksort($candidates, SORT_STRING);

        if (count($candidates) > 1) {
            $this->logger->warning('Multiple storefront sales channels found; using the lowest ID for product URLs', [
                'component' => 'product.sync',
                'sales_channel_ids' => array_keys($candidates),
            ]);
        }

        /** @var array{baseUrl: string, salesChannelId: string, languageId: string} $first */
        $first = reset($candidates);

        return $this->storefrontUrlBase = $first;
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

        $chosen = $domains->first();
        foreach ($domains as $domain) {
            if ($domain->getLanguageId() === $channel->getLanguageId()) {
                $chosen = $domain;

                break;
            }
        }

        if ($chosen === null) {
            return null;
        }

        // A domain without a usable URL cannot produce absolute product URLs;
        // treat it like no domain at all rather than emitting relative URLs.
        $baseUrl = rtrim((string) $chosen->getUrl(), '/');
        if ($baseUrl === '') {
            return null;
        }

        return [
            'baseUrl' => $baseUrl,
            'salesChannelId' => $channel->getId(),
            'languageId' => $chosen->getLanguageId(),
        ];
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
    public function deleteProduct(string $productId): void
    {
        try {
            $shopSlug = $this->systemConfigService->get('KarlaDelivery.config.shopSlug');
            $apiUrl = $this->systemConfigService->get('KarlaDelivery.config.apiUrl');
            $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode') ?? false;

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

            $this->sendRequestToKarlaApi($url, 'DELETE', []);
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
    private function sendRequestToKarlaApi(string $url, string $method, array $data): void
    {
        $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername');
        $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey');
        $requestTimeout = $this->systemConfigService->get('KarlaDelivery.config.requestTimeout') ?? 10.0;
        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode') ?? false;

        // Validate required config
        if (empty($apiUsername) || empty($apiKey)) {
            throw new \RuntimeException('Missing API credentials (apiUsername or apiKey)');
        }

        $auth = base64_encode($apiUsername . ':' . $apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        $requestOptions = [
            'headers' => $headers,
            'timeout' => $requestTimeout,
        ];

        // Only add body for PUT/POST
        if (in_array($method, ['PUT', 'POST'])) {
            $requestOptions['body'] = json_encode($data);
        }

        if ($debugMode) {
            $this->logger->debug('Preparing API request to Karla', [
                'component' => 'product.api',
                'method' => $method,
                'url' => $url,
                'payload' => $data,
            ]);
        }

        $response = $this->httpClient->request($method, $url, $requestOptions);
        $statusCode = $response->getStatusCode();

        if ($debugMode) {
            $content = $response->getContent(false); // false = don't throw on error status
            $this->logger->debug('API request to Karla completed', [
                'component' => 'product.api',
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
                'response' => $content,
            ]);
        }
    }
}
