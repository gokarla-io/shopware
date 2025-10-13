<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Doctrine\DBAL\Connection;
use Karla\Delivery\Service\ProductSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 * @covers \Karla\Delivery\Service\ProductSyncService
 */
class ProductSyncServiceTest extends TestCase
{
    /** @var EntityRepository&MockObject */
    private EntityRepository $productRepositoryMock;

    /** @var HttpClientInterface&MockObject */
    private HttpClientInterface $httpClientMock;

    /** @var SystemConfigService&MockObject */
    private SystemConfigService $systemConfigServiceMock;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $loggerMock;

    /** @var Connection&MockObject */
    private Connection $connectionMock;

    private ProductSyncService $service;

    protected function setUp(): void
    {
        $this->productRepositoryMock = $this->createMock(EntityRepository::class);
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->connectionMock = $this->createMock(Connection::class);

        $this->service = new ProductSyncService(
            $this->productRepositoryMock,
            $this->httpClientMock,
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->connectionMock
        );
    }

    public function testSyncProductBatchWithProducts(): void
    {
        // Mock product (standalone - no parent, no children)
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getId')->willReturn('product-id-123');
        $product->method('getParentId')->willReturn(null);
        $product->method('getChildCount')->willReturn(0);  // Standalone product
        $product->method('getActive')->willReturn(true);
        $product->method('getChildren')->willReturn(null);  // No children
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        // Mock search result
        $productCollection = new ProductCollection([$product]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getElements')->willReturn([$product]);
        $searchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $searchResult->method('count')->willReturn(1);
        $searchResult->method('getTotal')->willReturn(150); // More products exist

        $this->productRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);

        // Mock config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        // Mock HTTP response for bulk POST
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('[{"product_id":"product-id-123"}]');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.test.com/v1/shops/test-shop/products',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('body', $options);
                    $body = json_decode($options['body'], true);

                    // Verify it's an array (bulk)
                    $this->assertIsArray($body);
                    $this->assertCount(1, $body);

                    // Verify first variant structure
                    $this->assertEquals('product-id-123', $body[0]['product_id']);
                    $this->assertEquals('product-id-123', $body[0]['variant_id']);
                    $this->assertEquals('Test Product', $body[0]['title']);
                    $this->assertEquals('PROD-001', $body[0]['sku']);

                    return true;
                })
            )
            ->willReturn($response);

        // Act
        $hasMore = $this->service->syncProductBatch(0, 100);

        // Assert
        $this->assertTrue($hasMore); // 150 total > 100, so more products exist
    }

    public function testSyncProductBatchNoMoreProducts(): void
    {
        $productCollection = new ProductCollection([]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getElements')->willReturn([]);
        $searchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $searchResult->method('count')->willReturn(0);
        $searchResult->method('getTotal')->willReturn(50); // offset 100 + limit 100 > 50 total

        $this->productRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
        ]);

        // No HTTP call should be made
        $this->httpClientMock->expects($this->never())->method('request');

        // Act
        $hasMore = $this->service->syncProductBatch(100, 100);

        // Assert
        $this->assertFalse($hasMore);
    }

    public function testSyncProductBatchHandlesError(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-FAIL');
        $product->method('getId')->willReturn('product-fail');
        $product->method('getParentId')->willReturn(null);
        $product->method('getChildCount')->willReturn(0);  // Standalone product
        $product->method('getActive')->willReturn(true);
        $product->method('getChildren')->willReturn(null);
        $product->method('getName')->willReturn('Fail Product');
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $productCollection = new ProductCollection([$product]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getElements')->willReturn([$product]);
        $searchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $searchResult->method('count')->willReturn(1);
        $searchResult->method('getTotal')->willReturn(1);

        $this->productRepositoryMock->method('search')->willReturn($searchResult);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        // HTTP client throws exception
        $this->httpClientMock->method('request')
            ->willThrowException(new \Exception('Network error'));

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to sync product batch',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.bulk_sync'
                        && $context['count'] === 1
                        && $context['error'] === 'Network error';
                })
            );

        $hasMore = $this->service->syncProductBatch(0, 100);

        $this->assertFalse($hasMore);
    }

    public function testUpsertProductWithDebugMode(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-DEBUG');
        $product->method('getName')->willReturn('Debug Product');
        $product->method('getId')->willReturn('debug-id');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"product_id":"debug-id"}');

        $this->httpClientMock->method('request')->willReturn($response);

        // Expect debug logs (3 calls: upsertProduct, preparing request, request completed)
        $this->loggerMock->expects($this->exactly(3))
            ->method('debug')
            ->with(
                $this->logicalOr(
                    $this->equalTo('Upserting product to Karla'),
                    $this->equalTo('Preparing API request to Karla'),
                    $this->equalTo('API request to Karla completed')
                )
            );

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithVariant(): void
    {
        // Create parent product mock
        $priceMock = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price::class);
        $priceMock->method('getGross')->willReturn(29.99);
        $priceCollection = new \Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection([$priceMock]);

        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getName')->willReturn('Parent Product');
        $parentProduct->method('getProductNumber')->willReturn('PARENT-001');
        $parentProduct->method('getPrice')->willReturn($priceCollection);
        $parentProduct->method('getCover')->willReturn(null);
        $parentProduct->method('getId')->willReturn('parent-id-123');

        // Create variant product mock (no price, will inherit from parent)
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-VARIANT');
        $product->method('getName')->willReturn('Red Variant');
        $product->method('getId')->willReturn('variant-id-456');
        $product->method('getParentId')->willReturn('parent-id-123');
        $product->method('getPrice')->willReturn(null);  // Variant has no price
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/parent-id-123/variants/variant-id-456',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // IDs should NOT be in body (they're in URL)
                    $this->assertArrayNotHasKey('product_id', $body);
                    $this->assertArrayNotHasKey('variant_id', $body);

                    // Title should be parent's name
                    $this->assertEquals('Parent Product', $body['title']);
                    // Variant title should be variant's name
                    $this->assertEquals('Red Variant', $body['variant_title']);
                    // Price should be inherited from parent
                    $this->assertEquals(29.99, $body['price']);

                    return true;
                })
            )
            ->willReturn($response);

        // Pass parent entity explicitly
        $this->service->upsertProduct($product, $parentProduct);
    }

    public function testUpsertProductVariantWithParentImageFallback(): void
    {
        // Create mocks for parent's media
        $parentMediaMock = $this->createMock(\Shopware\Core\Content\Media\MediaEntity::class);
        $parentMediaMock->method('getUrl')->willReturn('https://example.com/parent-image.jpg');

        $parentCoverMock = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity::class);
        $parentCoverMock->method('getMedia')->willReturn($parentMediaMock);

        // Create parent's price
        $parentPriceMock = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price::class);
        $parentPriceMock->method('getGross')->willReturn(49.99);
        $parentPriceCollection = new \Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection([$parentPriceMock]);

        // Create parent product mock
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getName')->willReturn('Parent T-Shirt');
        $parentProduct->method('getProductNumber')->willReturn('PARENT-TSHIRT');
        $parentProduct->method('getPrice')->willReturn($parentPriceCollection);
        $parentProduct->method('getCover')->willReturn($parentCoverMock);

        // Create variant product mock (no image, no price - will inherit both from parent)
        $variantProduct = $this->createMock(ProductEntity::class);
        $variantProduct->method('getProductNumber')->willReturn('VARIANT-NO-IMAGE');
        $variantProduct->method('getName')->willReturn('Blue XL');
        $variantProduct->method('getId')->willReturn('variant-id-789');
        $variantProduct->method('getParentId')->willReturn('parent-id-999');
        $variantProduct->method('getPrice')->willReturn(null);  // No price
        $variantProduct->method('getCover')->willReturn(null);  // No image
        $variantProduct->method('getTranslations')->willReturn(null);

        // Add parent ID to parent product
        $parentProduct->method('getId')->willReturn('parent-id-999');

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/parent-id-999/variants/variant-id-789',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Should have parent's image as fallback
                    $this->assertArrayHasKey('image_url', $body);
                    $this->assertEquals('https://example.com/parent-image.jpg', $body['image_url']);

                    // Should have parent's name as title
                    $this->assertEquals('Parent T-Shirt', $body['title']);
                    // Should have variant's name as variant_title
                    $this->assertEquals('Blue XL', $body['variant_title']);
                    // Should have parent's price as fallback
                    $this->assertEquals(49.99, $body['price']);

                    return true;
                })
            )
            ->willReturn($response);

        // Pass parent entity explicitly
        $this->service->upsertProduct($variantProduct, $parentProduct);
    }

    public function testUpsertProductVariantWithParentEmptyNameAndNumber(): void
    {
        // Test variant with parent that has empty name and product number
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getName')->willReturn(''); // Empty name!
        $parentProduct->method('getProductNumber')->willReturn(''); // Empty number!
        $parentProduct->method('getId')->willReturn('parent-id-empty');
        $parentProduct->method('getPrice')->willReturn(null);
        $parentProduct->method('getCover')->willReturn(null);

        $variantProduct = $this->createMock(ProductEntity::class);
        $variantProduct->method('getProductNumber')->willReturn('VARIANT-001');
        $variantProduct->method('getName')->willReturn('Blue Variant');
        $variantProduct->method('getId')->willReturn('variant-id-blue');
        $variantProduct->method('getParentId')->willReturn('parent-id-empty');
        $variantProduct->method('getPrice')->willReturn(null);
        $variantProduct->method('getCover')->willReturn(null);
        $variantProduct->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/parent-id-empty/variants/variant-id-blue',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Parent has empty name/number, should fallback to 'Unknown Product'
                    $this->assertEquals('Unknown Product', $body['title']);
                    // Variant should have its own name
                    $this->assertEquals('Blue Variant', $body['variant_title']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($variantProduct, $parentProduct);
    }

    public function testUpsertProductWithAllFields(): void
    {
        // Create mocks for related entities
        $mediaMock = $this->createMock(\Shopware\Core\Content\Media\MediaEntity::class);
        $mediaMock->method('getUrl')->willReturn('https://example.com/image.jpg');

        $coverMock = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity::class);
        $coverMock->method('getMedia')->willReturn($mediaMock);

        $priceMock = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price::class);
        $priceMock->method('getGross')->willReturn(99.99);
        $priceCollection = new \Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection([$priceMock]);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-FULL');
        $product->method('getName')->willReturn('Full Product');
        $product->method('getId')->willReturn('full-id-123');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn($priceCollection);
        $product->method('getCover')->willReturn($coverMock);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                $this->anything(),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Verify fields are present
                    $this->assertEquals('Full Product', $body['title']);
                    $this->assertEquals(99.99, $body['price']);
                    $this->assertEquals('PROD-FULL', $body['sku']);
                    $this->assertEquals('https://example.com/image.jpg', $body['image_url']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testDeleteProduct(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $response->method('getContent')->with(false)->willReturn('');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'DELETE',
                'https://api.test.com/v1/shops/test-shop/products/product-to-delete',
                $this->callback(function ($options) {
                    $this->assertArrayNotHasKey('body', $options);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->deleteProduct('product-to-delete');
    }

    public function testDeleteProductWithDebugMode(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $response->method('getContent')->with(false)->willReturn('');

        $this->httpClientMock->method('request')->willReturn($response);

        // Expect debug logs
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('debug');

        $this->service->deleteProduct('product-debug-delete');
    }

    public function testUpsertProductWithTranslations(): void
    {
        // Mock language and locale entities
        $localeDe = $this->createMock(\Shopware\Core\System\Locale\LocaleEntity::class);
        $localeDe->method('getCode')->willReturn('de-DE');

        $localeEn = $this->createMock(\Shopware\Core\System\Locale\LocaleEntity::class);
        $localeEn->method('getCode')->willReturn('en-GB');

        $languageDe = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $languageDe->method('getLocale')->willReturn($localeDe);

        $languageEn = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $languageEn->method('getLocale')->willReturn($localeEn);

        // Mock translations
        $translationDe = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translationDe->method('getLanguage')->willReturn($languageDe);
        $translationDe->method('getName')->willReturn('Deutsches Produkt');

        $translationEn = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translationEn->method('getLanguage')->willReturn($languageEn);
        $translationEn->method('getName')->willReturn('English Product');

        $translationCollection = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection::class);
        $translationCollection->method('getIterator')->willReturn(new \ArrayIterator([$translationDe, $translationEn]));

        // Mock product with translations
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-TRANS');
        $product->method('getName')->willReturn('Product Name');
        $product->method('getId')->willReturn('product-trans-id');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn($translationCollection);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-trans-id/variants/product-trans-id',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Should have translations
                    $this->assertArrayHasKey('translations', $body);
                    $this->assertArrayHasKey('de', $body['translations']);
                    $this->assertArrayHasKey('en', $body['translations']);
                    $this->assertEquals('Deutsches Produkt', $body['translations']['de']['title']);
                    $this->assertEquals('English Product', $body['translations']['en']['title']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithEmptyTranslations(): void
    {
        // Test case 1: Translation with no language
        $translationNoLang = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translationNoLang->method('getLanguage')->willReturn(null);

        // Test case 2: Translation with language but no locale
        $languageNoLocale = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $languageNoLocale->method('getLocale')->willReturn(null);

        $translationNoLocale = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translationNoLocale->method('getLanguage')->willReturn($languageNoLocale);

        // Test case 3: Translation with locale but no name
        $locale = $this->createMock(\Shopware\Core\System\Locale\LocaleEntity::class);
        $locale->method('getCode')->willReturn('fr-FR');

        $language = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $language->method('getLocale')->willReturn($locale);

        $translationNoName = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translationNoName->method('getLanguage')->willReturn($language);
        $translationNoName->method('getName')->willReturn(null);

        $translationCollection = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection::class);
        $translationCollection->method('getIterator')->willReturn(new \ArrayIterator([
            $translationNoLang,
            $translationNoLocale,
            $translationNoName,
        ]));

        // Mock product with edge case translations
        $product = $this->createMock(ProductEntity::class);
        $product->method('getProductNumber')->willReturn('PROD-EMPTY-TRANS');
        $product->method('getName')->willReturn('Product Name');
        $product->method('getId')->willReturn('product-empty-trans-id');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn($translationCollection);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-empty-trans-id/variants/product-empty-trans-id',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Should NOT have translations key since all translations are empty/invalid
                    $this->assertArrayNotHasKey('translations', $body);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductHandlesException(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id-fail');
        $product->method('getProductNumber')->willReturn('PROD-FAIL');
        $product->method('getName')->willReturn('Fail Product');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // HTTP client throws exception (network error, timeout, etc)
        $this->httpClientMock->method('request')
            ->willThrowException(new \Exception('Network timeout'));

        // Expect error log - exception should be caught and logged
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to upsert product to Karla',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.sync'
                        && $context['product_id'] === 'product-id-fail'
                        && $context['product_number'] === 'PROD-FAIL'
                        && $context['error'] === 'Network timeout';
                })
            );

        // Should NOT throw - errors are logged only
        $this->service->upsertProduct($product);
    }

    public function testDeleteProductHandlesException(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // HTTP client throws exception
        $this->httpClientMock->method('request')
            ->willThrowException(new \Exception('API unavailable'));

        // Expect error log - exception should be caught and logged
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to delete product from Karla',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.sync'
                        && $context['product_id'] === 'product-to-delete'
                        && $context['error'] === 'API unavailable';
                })
            );

        // Should NOT throw - errors are logged only
        $this->service->deleteProduct('product-to-delete');
    }

    public function testUpsertProductWithNullProductName(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-no-name');
        $product->method('getProductNumber')->willReturn('PROD-NO-NAME');
        $product->method('getName')->willReturn(null); // Null name!
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        // Verify fallback to product number when name is null
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-no-name/variants/product-no-name',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    // Should fallback to product number
                    $this->assertEquals('PROD-NO-NAME', $body['title']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithNullProductNameAndNumber(): void
    {
        // Test the edge case where BOTH name and product number are empty/null
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-unknown');
        $product->method('getProductNumber')->willReturn(''); // Empty product number!
        $product->method('getName')->willReturn(null); // Null name!
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        // Verify fallback to 'Unknown Product' when both name and product number are null
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-unknown/variants/product-unknown',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    // Should fallback to 'Unknown Product'
                    $this->assertEquals('Unknown Product', $body['title']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithMissingConfig(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id');
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');

        // Missing apiUrl config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, null], // Missing!
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Expect error log about missing config
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to upsert product to Karla',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Missing required config');
                })
            );

        // Should NOT throw
        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithEmptyTranslationLocale(): void
    {
        // Mock locale with empty code
        $locale = $this->createMock(\Shopware\Core\System\Locale\LocaleEntity::class);
        $locale->method('getCode')->willReturn(''); // Empty locale code!

        $language = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $language->method('getLocale')->willReturn($locale);

        $translation = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translation->method('getLanguage')->willReturn($language);
        $translation->method('getName')->willReturn('Some Translation');

        $translationCollection = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection::class);
        $translationCollection->method('getIterator')->willReturn(new \ArrayIterator([$translation]));

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id');
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn($translationCollection);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-id/variants/product-id',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    // Should NOT have translations (empty locale code skipped)
                    $this->assertArrayNotHasKey('translations', $body);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testUpsertProductWithShortTranslationLocale(): void
    {
        // Mock locale with single character code (< 2 chars)
        $locale = $this->createMock(\Shopware\Core\System\Locale\LocaleEntity::class);
        $locale->method('getCode')->willReturn('x'); // Only 1 char!

        $language = $this->createMock(\Shopware\Core\System\Language\LanguageEntity::class);
        $language->method('getLocale')->willReturn($locale);

        $translation = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity::class);
        $translation->method('getLanguage')->willReturn($language);
        $translation->method('getName')->willReturn('Some Translation');

        $translationCollection = $this->createMock(\Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection::class);
        $translationCollection->method('getIterator')->willReturn(new \ArrayIterator([$translation]));

        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id');
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getParentId')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn($translationCollection);

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.test.com/v1/shops/test-shop/products/product-id/variants/product-id',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    // Should NOT have translations (short locale code skipped)
                    $this->assertArrayNotHasKey('translations', $body);

                    return true;
                })
            )
            ->willReturn($response);

        $this->service->upsertProduct($product);
    }

    public function testSyncProductBatchWithMissingCredentials(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id');
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getParentId')->willReturn(null);
        $product->method('getChildCount')->willReturn(0);  // Standalone product
        $product->method('getActive')->willReturn(true);
        $product->method('getChildren')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $productCollection = new ProductCollection([$product]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getElements')->willReturn([$product]);
        $searchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $searchResult->method('count')->willReturn(1);
        $searchResult->method('getTotal')->willReturn(1);

        $this->productRepositoryMock->method('search')->willReturn($searchResult);

        // Missing apiKey
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, null], // Missing!
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        // Expect error log about missing credentials
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to sync product batch',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Missing API credentials');
                })
            );

        $hasMore = $this->service->syncProductBatch(0, 50);
        $this->assertFalse($hasMore); // Should complete without throwing
    }

    public function testSyncProductBatchWithMissingShopConfig(): void
    {
        $product = $this->createMock(ProductEntity::class);
        $product->method('getId')->willReturn('product-id');
        $product->method('getProductNumber')->willReturn('PROD-001');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getParentId')->willReturn(null);
        $product->method('getChildCount')->willReturn(0);  // Standalone product
        $product->method('getActive')->willReturn(true);
        $product->method('getChildren')->willReturn(null);
        $product->method('getPrice')->willReturn(null);
        $product->method('getCover')->willReturn(null);
        $product->method('getTranslations')->willReturn(null);

        $productCollection = new ProductCollection([$product]);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getElements')->willReturn([$product]);
        $searchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $searchResult->method('count')->willReturn(1);
        $searchResult->method('getTotal')->willReturn(1);

        $this->productRepositoryMock->method('search')->willReturn($searchResult);

        // Missing shopSlug
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, null], // Missing!
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Expect error log about missing config
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to sync product batch',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Missing required config');
                })
            );

        $hasMore = $this->service->syncProductBatch(0, 50);
        $this->assertFalse($hasMore); // Should complete without throwing
    }

    public function testDeleteProductWithMissingCredentials(): void
    {
        // Missing apiUsername
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, null], // Missing!
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to delete product from Karla',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Missing API credentials');
                })
            );

        // Should NOT throw
        $this->service->deleteProduct('product-id');
    }

    public function testDeleteProductWithMissingShopConfig(): void
    {
        // Missing apiUrl
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, null], // Missing!
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to delete product from Karla',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Missing required config');
                })
            );

        // Should NOT throw
        $this->service->deleteProduct('product-id');
    }

    public function testSyncProductBatchExpandsParentProductVariants(): void
    {
        // With displayParent context, Shopware returns BOTH parent AND variants
        // We simulate getting parent + 2 variants in the search result

        // Create parent product (will be skipped from sync, but used as reference for variants)
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getProductNumber')->willReturn('PARENT-001');
        $parentProduct->method('getName')->willReturn('Parent Product');
        $parentProduct->method('getId')->willReturn('43a23e0c03bf4ceabc6055a2185faa87');
        $parentProduct->method('getUniqueIdentifier')->willReturn('43a23e0c03bf4ceabc6055a2185faa87');
        $parentProduct->method('getParentId')->willReturn(null);
        $parentProduct->method('getChildCount')->willReturn(2);  // Has 2 variants
        $parentProduct->method('getActive')->willReturn(true);
        $parentProduct->method('getPrice')->willReturn(null);
        $parentProduct->method('getCover')->willReturn(null);

        // Create variant 1 (will be synced)
        $variant1 = $this->createMock(ProductEntity::class);
        $variant1->method('getProductNumber')->willReturn('VARIANT-001');
        $variant1->method('getName')->willReturn('Variant 1');
        $variant1->method('getId')->willReturn('variant-id-1');
        $variant1->method('getUniqueIdentifier')->willReturn('variant-id-1');
        $variant1->method('getParentId')->willReturn('43a23e0c03bf4ceabc6055a2185faa87');
        $variant1->method('getParent')->willReturn($parentProduct);
        $variant1->method('getChildCount')->willReturn(0);
        $variant1->method('getActive')->willReturn(true);
        $variant1->method('getPrice')->willReturn(null);
        $variant1->method('getCover')->willReturn(null);
        $variant1->method('getTranslations')->willReturn(null);

        // Create variant 2 (will be synced)
        $variant2 = $this->createMock(ProductEntity::class);
        $variant2->method('getProductNumber')->willReturn('VARIANT-002');
        $variant2->method('getName')->willReturn('Variant 2');
        $variant2->method('getId')->willReturn('variant-id-2');
        $variant2->method('getUniqueIdentifier')->willReturn('variant-id-2');
        $variant2->method('getParentId')->willReturn('43a23e0c03bf4ceabc6055a2185faa87');
        $variant2->method('getParent')->willReturn($parentProduct);
        $variant2->method('getChildCount')->willReturn(0);
        $variant2->method('getActive')->willReturn(true);
        $variant2->method('getPrice')->willReturn(null);
        $variant2->method('getCover')->willReturn(null);
        $variant2->method('getTranslations')->willReturn(null);

        // Mock search results:
        // First call returns parent only
        $parentCollection = new ProductCollection([$parentProduct]);
        $parentSearchResult = $this->createMock(EntitySearchResult::class);
        $parentSearchResult->method('getElements')->willReturn([$parentProduct]);
        $parentSearchResult->method('getIterator')->willReturn($parentCollection->getIterator());
        $parentSearchResult->method('count')->willReturn(1);
        $parentSearchResult->method('getTotal')->willReturn(1);

        // Mock database queries: 1) parent version check, 2) all variants, 3) active variants
        $this->connectionMock->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // Parent version info
                [['id' => '43a23e0c03bf4ceabc6055a2185faa87', 'product_number' => 'PARENT-001', 'version_id' => '0FA91CE3E96A4BC2BE4BD9CE752C3425']],
                // All variants (including inactive)
                [
                    ['id' => 'variantid1', 'product_number' => 'VARIANT-001', 'active' => 1],
                    ['id' => 'variantid2', 'product_number' => 'VARIANT-002', 'active' => 1],
                ],
                // Active variants only
                [
                    ['id' => 'variantid1', 'product_number' => 'VARIANT-001'],
                    ['id' => 'variantid2', 'product_number' => 'VARIANT-002'],
                ]
            );

        // Second call loads variants using Criteria with IDs
        $variantCollection = new ProductCollection([$variant1, $variant2]);
        $variantSearchResult = $this->createMock(EntitySearchResult::class);
        $variantSearchResult->method('getElements')->willReturn([$variant1, $variant2]);
        $variantSearchResult->method('getIterator')->willReturn($variantCollection->getIterator());
        $variantSearchResult->method('count')->willReturn(2);
        $variantSearchResult->method('getTotal')->willReturn(2);

        // Third call loads parent products separately
        $parentOnlyCollection = new ProductCollection([$parentProduct]);
        $parentOnlySearchResult = $this->createMock(EntitySearchResult::class);
        $parentOnlySearchResult->method('getElements')->willReturn([$parentProduct]);
        $parentOnlySearchResult->method('getIterator')->willReturn($parentOnlyCollection->getIterator());
        $parentOnlySearchResult->method('count')->willReturn(1);
        $parentOnlySearchResult->method('getTotal')->willReturn(1);

        // Expect 3 calls: first for parents, second for variants by IDs, third for parent entities
        $this->productRepositoryMock->expects($this->exactly(3))
            ->method('search')
            ->willReturnOnConsecutiveCalls($parentSearchResult, $variantSearchResult, $parentOnlySearchResult);

        // Mock config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, true],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        // Mock HTTP response for bulk POST
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('[{"product_id":"parent-id-123"}]');

        // Verify that we send BOTH variants (not the parent)
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.test.com/v1/shops/test-shop/products',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Should have 2 variants, not the parent
                    $this->assertIsArray($body);
                    $this->assertCount(2, $body, 'Should sync 2 variants, not the parent');

                    // Check variant 1
                    $this->assertEquals('43a23e0c03bf4ceabc6055a2185faa87', $body[0]['product_id']);
                    $this->assertEquals('variant-id-1', $body[0]['variant_id']);
                    $this->assertEquals('Parent Product', $body[0]['title']);  // Parent's name
                    $this->assertEquals('Variant 1', $body[0]['variant_title']);  // Variant's name

                    // Check variant 2
                    $this->assertEquals('43a23e0c03bf4ceabc6055a2185faa87', $body[1]['product_id']);
                    $this->assertEquals('variant-id-2', $body[1]['variant_id']);
                    $this->assertEquals('Parent Product', $body[1]['title']);  // Parent's name
                    $this->assertEquals('Variant 2', $body[1]['variant_title']);  // Variant's name

                    return true;
                })
            )
            ->willReturn($response);

        // Act
        $hasMore = $this->service->syncProductBatch(0, 100);

        // Assert
        $this->assertFalse($hasMore);
    }

    public function testSyncProductBatchSkipsInactiveVariants(): void
    {
        // In reality, the database query filters by active=true, so inactive variants
        // wouldn't be returned at all. But we test the defensive logic anyway.

        // Create parent product (will be skipped)
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getProductNumber')->willReturn('PARENT-002');
        $parentProduct->method('getName')->willReturn('Parent Product 2');
        $parentProduct->method('getId')->willReturn('c7bca22753c84d08b6178a50052b4146');
        $parentProduct->method('getUniqueIdentifier')->willReturn('c7bca22753c84d08b6178a50052b4146');
        $parentProduct->method('getParentId')->willReturn(null);
        $parentProduct->method('getChildCount')->willReturn(2);
        $parentProduct->method('getActive')->willReturn(true);
        $parentProduct->method('getPrice')->willReturn(null);
        $parentProduct->method('getCover')->willReturn(null);

        // Active variant (will be synced)
        $activeVariant = $this->createMock(ProductEntity::class);
        $activeVariant->method('getProductNumber')->willReturn('VARIANT-ACTIVE');
        $activeVariant->method('getName')->willReturn('Active Variant');
        $activeVariant->method('getId')->willReturn('variant-active-id');
        $activeVariant->method('getUniqueIdentifier')->willReturn('variant-active-id');
        $activeVariant->method('getParentId')->willReturn('parent-id-456');
        $activeVariant->method('getParent')->willReturn($parentProduct);
        $activeVariant->method('getChildCount')->willReturn(0);
        $activeVariant->method('getActive')->willReturn(true);
        $activeVariant->method('getPrice')->willReturn(null);
        $activeVariant->method('getCover')->willReturn(null);
        $activeVariant->method('getTranslations')->willReturn(null);

        // Mock search results:
        // First call returns parent only
        $parentCollection = new ProductCollection([$parentProduct]);
        $parentSearchResult = $this->createMock(EntitySearchResult::class);
        $parentSearchResult->method('getElements')->willReturn([$parentProduct]);
        $parentSearchResult->method('getIterator')->willReturn($parentCollection->getIterator());
        $parentSearchResult->method('count')->willReturn(1);
        $parentSearchResult->method('getTotal')->willReturn(1);

        // Mock database queries: 1) parent version check, 2) all variants, 3) active variants
        $this->connectionMock->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // Parent version info
                [['id' => 'c7bca22753c84d08b6178a50052b4146', 'product_number' => 'PARENT-002', 'version_id' => '0FA91CE3E96A4BC2BE4BD9CE752C3425']],
                // All variants
                [['id' => 'variantactiveid', 'product_number' => 'VARIANT-ACTIVE', 'active' => null]],
                // Active variants (same since parent is active)
                [['id' => 'variantactiveid', 'product_number' => 'VARIANT-ACTIVE']]
            );

        // Second call loads active variant
        $variantCollection = new ProductCollection([$activeVariant]);
        $variantSearchResult = $this->createMock(EntitySearchResult::class);
        $variantSearchResult->method('getElements')->willReturn([$activeVariant]);
        $variantSearchResult->method('getIterator')->willReturn($variantCollection->getIterator());
        $variantSearchResult->method('count')->willReturn(1);
        $variantSearchResult->method('getTotal')->willReturn(1);

        // Third call loads parent products separately
        $parentOnlyCollection = new ProductCollection([$parentProduct]);
        $parentOnlySearchResult = $this->createMock(EntitySearchResult::class);
        $parentOnlySearchResult->method('getElements')->willReturn([$parentProduct]);
        $parentOnlySearchResult->method('getIterator')->willReturn($parentOnlyCollection->getIterator());
        $parentOnlySearchResult->method('count')->willReturn(1);
        $parentOnlySearchResult->method('getTotal')->willReturn(1);

        $this->productRepositoryMock->expects($this->exactly(3))
            ->method('search')
            ->willReturnOnConsecutiveCalls($parentSearchResult, $variantSearchResult, $parentOnlySearchResult);

        // Mock config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
        ]);

        // Mock HTTP response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        // Verify that we only send the ACTIVE variant
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Should only have 1 variant (the active one)
                    $this->assertCount(1, $body, 'Should only sync active variant');
                    $this->assertEquals('variant-active-id', $body[0]['variant_id']);

                    return true;
                })
            )
            ->willReturn($response);

        $hasMore = $this->service->syncProductBatch(0, 100);
        $this->assertFalse($hasMore);
    }

    public function testSyncProductBatchContinuesWhenNoProductsToSyncButMoreExist(): void
    {
        // Edge case: Only parent returned, all variants are inactive
        // Nothing to sync, but more products exist in the database
        $parentProduct = $this->createMock(ProductEntity::class);
        $parentProduct->method('getProductNumber')->willReturn('PARENT-003');
        $parentProduct->method('getId')->willReturn('d8efa3b9e4a94f28a6c1b7905f2e3d46');
        $parentProduct->method('getUniqueIdentifier')->willReturn('d8efa3b9e4a94f28a6c1b7905f2e3d46');
        $parentProduct->method('getParentId')->willReturn(null);
        $parentProduct->method('getChildCount')->willReturn(1);
        $parentProduct->method('getActive')->willReturn(true);

        // Mock search results:
        // First call returns parent only
        $productCollection = new ProductCollection([$parentProduct]);
        $parentSearchResult = $this->createMock(EntitySearchResult::class);
        $parentSearchResult->method('getElements')->willReturn([$parentProduct]);
        $parentSearchResult->method('getIterator')->willReturn($productCollection->getIterator());
        $parentSearchResult->method('count')->willReturn(1);
        $parentSearchResult->method('getTotal')->willReturn(200);  // More products exist!

        // Mock database queries: 1) parent version check, 2) all variants, 3) filtered variants
        $this->connectionMock->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // Parent version info
                [['id' => 'd8efa3b9e4a94f28a6c1b7905f2e3d46', 'product_number' => 'PARENT-003', 'version_id' => '0FA91CE3E96A4BC2BE4BD9CE752C3425']],
                // All variants found
                [['id' => 'someinactivevariant', 'product_number' => 'INACTIVE-VAR', 'active' => null]],
                // But we return empty (simulating no variants match criteria)
                []
            );

        // No second repository call since no variant IDs to load
        $this->productRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($parentSearchResult);

        // Mock config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // No HTTP request should be made (no products to sync)
        $this->httpClientMock->expects($this->never())->method('request');

        // Act - offset 0, limit 100, but total is 200
        $hasMore = $this->service->syncProductBatch(0, 100);

        // Assert - should return TRUE because (0 + 100) < 200
        $this->assertTrue($hasMore, 'Should continue to next batch even though nothing was synced');
    }
}
