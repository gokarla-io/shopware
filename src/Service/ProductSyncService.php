<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
     * @codeCoverageIgnore Simple dependency injection constructor
     */
    public function __construct(
        private EntityRepository $productRepository,
        private HttpClientInterface $httpClient,
        private SystemConfigService $systemConfigService,
        private LoggerInterface $logger,
        private Connection $connection
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
            $variantPayload = $this->buildVariantPayload($product);

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

        // Build array of variant payloads
        // buildVariantPayload() is fully defensive and cannot throw
        $variantPayloads = [];
        foreach ($products as $product) {
            $variantPayloads[] = $this->buildVariantPayload($product);
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
     * Build product variant payload for Karla API
     */
    private function buildVariantPayload(ProductEntity $product): array
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
            'product_url' => null,  // Shopware doesn't expose product URLs in entity
        ];

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
