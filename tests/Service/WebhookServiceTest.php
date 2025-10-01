<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\WebhookService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Service\WebhookService
 */
final class WebhookServiceTest extends TestCase
{
    /** @var SystemConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private SystemConfigService $systemConfigServiceMock;

    /** @var HttpClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private HttpClientInterface $httpClientMock;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $loggerMock;

    private WebhookService $webhookService;

    // Test configuration constants
    private const TEST_SHOP_SLUG = 'test-shop';
    private const TEST_API_USERNAME = 'testuser';
    private const TEST_API_KEY = 'test-api-key';
    private const TEST_API_URL = 'https://api.example.com';
    private const TEST_BASE_URL = 'https://shop.example.com';
    private const TEST_WEBHOOK_URL = 'https://shop.example.com/api/karla/webhooks/abc123';
    private const TEST_WEBHOOK_SECRET = 'secret-uuid-123';
    private const TEST_WEBHOOK_ID = 'webhook-id-456';

    protected function setUp(): void
    {
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->webhookService = new WebhookService(
            $this->systemConfigServiceMock,
            $this->httpClientMock,
            $this->loggerMock,
        );
    }

    /**
     * @covers ::generateWebhookUrl
     */
    public function testGenerateWebhookUrlCreatesValidUrl(): void
    {
        // Arrange: No setup needed

        // Act: Generate webhook URL with base URL
        $webhookUrl = $this->webhookService->generateWebhookUrl(self::TEST_BASE_URL);

        // Assert: URL follows expected pattern
        $this->assertStringStartsWith(self::TEST_BASE_URL . '/api/karla/webhooks/', $webhookUrl);
        $this->assertMatchesRegularExpression('/\/api\/karla\/webhooks\/[a-f0-9]{32}$/', $webhookUrl);
    }

    /**
     * @covers ::generateWebhookUrl
     */
    public function testGenerateWebhookUrlCreatesUniqueUrls(): void
    {
        // Arrange: No setup needed

        // Act: Generate two webhook URLs
        $url1 = $this->webhookService->generateWebhookUrl(self::TEST_BASE_URL);
        $url2 = $this->webhookService->generateWebhookUrl(self::TEST_BASE_URL);

        // Assert: URLs are different (unique slugs)
        $this->assertNotEquals($url1, $url2);
    }

    /**
     * @covers ::generateWebhookUrl
     */
    public function testGenerateWebhookUrlConvertsHttpToHttps(): void
    {
        // Arrange: Base URL with HTTP
        $httpBaseUrl = 'http://shop.example.com';

        // Act: Generate webhook URL
        $webhookUrl = $this->webhookService->generateWebhookUrl($httpBaseUrl);

        // Assert: URL is converted to HTTPS
        $this->assertStringStartsWith('https://shop.example.com/api/karla/webhooks/', $webhookUrl);
        $this->assertStringNotContainsString('http://', $webhookUrl);
    }

    /**
     * @covers ::generateWebhookUrl
     */
    public function testGenerateWebhookUrlPreservesHttps(): void
    {
        // Arrange: Base URL already with HTTPS
        $httpsBaseUrl = 'https://shop.example.com';

        // Act: Generate webhook URL
        $webhookUrl = $this->webhookService->generateWebhookUrl($httpsBaseUrl);

        // Assert: HTTPS is preserved
        $this->assertStringStartsWith('https://shop.example.com/api/karla/webhooks/', $webhookUrl);
    }


    /**
     * @covers ::createWebhook
     */
    public function testCreateWebhookSuccessfully(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('toArray')->willReturn([
            'uuid' => self::TEST_WEBHOOK_ID,
            'url' => self::TEST_WEBHOOK_URL,
            'secret' => self::TEST_WEBHOOK_SECRET,
            'enabled_events' => ['*'],
            'status' => 'active',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                self::TEST_API_URL . '/v1/shops/' . self::TEST_SHOP_SLUG . '/webhooks',
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('auth_basic', $options);
                    $this->assertEquals([self::TEST_API_USERNAME, self::TEST_API_KEY], $options['auth_basic']);
                    $this->assertArrayHasKey('json', $options);
                    $this->assertEquals(self::TEST_WEBHOOK_URL, $options['json']['url']);
                    $this->assertArrayNotHasKey('secret', $options['json']); // Secret NOT sent - Karla generates it
                    $this->assertEquals(['*'], $options['json']['enabled_events']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        // Act: Create webhook (no secret parameter - Karla generates it)
        $webhookData = $this->webhookService->createWebhook(
            self::TEST_WEBHOOK_URL,
            ['*'],
        );

        // Assert: Webhook UUID and secret are returned
        $this->assertIsArray($webhookData);
        $this->assertEquals(self::TEST_WEBHOOK_ID, $webhookData['uuid']);
        $this->assertEquals(self::TEST_WEBHOOK_SECRET, $webhookData['secret']);
    }

    /**
     * @covers ::createWebhook
     */
    public function testCreateWebhookWithSpecificEvents(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('toArray')->willReturn([
            'uuid' => self::TEST_WEBHOOK_ID,
            'secret' => self::TEST_WEBHOOK_SECRET,
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options): bool {
                    $this->assertEquals(['order.shipped', 'order.delivered'], $options['json']['enabled_events']);
                    $this->assertArrayNotHasKey('secret', $options['json']); // Secret NOT sent

                    return true;
                }),
            )
            ->willReturn($responseMock);

        // Act: Create webhook with specific events (no secret parameter)
        $webhookData = $this->webhookService->createWebhook(
            self::TEST_WEBHOOK_URL,
            ['order.shipped', 'order.delivered'],
        );

        // Assert: Webhook data is returned
        $this->assertIsArray($webhookData);
        $this->assertEquals(self::TEST_WEBHOOK_ID, $webhookData['uuid']);
        $this->assertEquals(self::TEST_WEBHOOK_SECRET, $webhookData['secret']);
    }

    /**
     * @covers ::createWebhook
     */
    public function testCreateWebhookThrowsExceptionOnApiError(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('API Error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to create webhook',
                $this->callback(function (array $context): bool {
                    $this->assertArrayHasKey('error', $context);
                    $this->assertEquals('API Error', $context['error']);

                    return true;
                }),
            );

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create webhook: API Error');

        // Act: Create webhook (no secret parameter)
        $this->webhookService->createWebhook(
            self::TEST_WEBHOOK_URL,
            ['*'],
        );
    }

    /**
     * @covers ::updateWebhook
     */
    public function testUpdateWebhookSuccessfully(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $responseMock = $this->createMock(ResponseInterface::class);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                self::TEST_API_URL . '/v1/shops/' . self::TEST_SHOP_SLUG . '/webhooks/' . self::TEST_WEBHOOK_ID,
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('auth_basic', $options);
                    $this->assertEquals([self::TEST_API_USERNAME, self::TEST_API_KEY], $options['auth_basic']);
                    $this->assertArrayHasKey('json', $options);
                    $this->assertEquals(self::TEST_WEBHOOK_URL, $options['json']['url']);
                    $this->assertArrayNotHasKey('secret', $options['json']); // Secret NOT sent - managed by Karla
                    $this->assertEquals(['*'], $options['json']['enabled_events']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        // Act: Update webhook (no secret parameter)
        $this->webhookService->updateWebhook(
            self::TEST_WEBHOOK_ID,
            self::TEST_WEBHOOK_URL,
            ['*'],
        );

        // Assert: Expectations set in mock
    }

    /**
     * @covers ::updateWebhook
     */
    public function testUpdateWebhookThrowsExceptionOnApiError(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('API Error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to update webhook', $this->anything());

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to update webhook: API Error');

        // Act: Update webhook (no secret parameter)
        $this->webhookService->updateWebhook(
            self::TEST_WEBHOOK_ID,
            self::TEST_WEBHOOK_URL,
            ['*'],
        );
    }

    /**
     * @covers ::deleteWebhook
     */
    public function testDeleteWebhookSuccessfully(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $responseMock = $this->createMock(ResponseInterface::class);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'DELETE',
                self::TEST_API_URL . '/v1/shops/' . self::TEST_SHOP_SLUG . '/webhooks/' . self::TEST_WEBHOOK_ID,
                $this->callback(function (array $options): bool {
                    $this->assertArrayHasKey('auth_basic', $options);
                    $this->assertEquals([self::TEST_API_USERNAME, self::TEST_API_KEY], $options['auth_basic']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        // Act: Delete webhook
        $this->webhookService->deleteWebhook(self::TEST_WEBHOOK_ID);

        // Assert: Expectations set in mock
    }

    /**
     * @covers ::deleteWebhook
     */
    public function testDeleteWebhookThrowsExceptionOnApiError(): void
    {
        // Arrange: Configure system config
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('API Error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to delete webhook', $this->anything());

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete webhook: API Error');

        // Act: Delete webhook
        $this->webhookService->deleteWebhook(self::TEST_WEBHOOK_ID);
    }

    /**
     * @covers ::createWebhook
     */
    public function testCreateWebhookThrowsExceptionWhenMissingConfig(): void
    {
        // Arrange: Missing shop slug
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, null],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Missing required configuration for webhook createWebhook');

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required configuration: shopSlug, apiUsername, apiKey, or apiUrl');

        // Act: Create webhook (no secret parameter)
        $this->webhookService->createWebhook(
            self::TEST_WEBHOOK_URL,
            ['*'],
        );
    }

    /**
     * @covers ::updateWebhook
     */
    public function testUpdateWebhookThrowsExceptionWhenMissingConfig(): void
    {
        // Arrange: Missing API key
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, null],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Missing required configuration for webhook updateWebhook');

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required configuration: shopSlug, apiUsername, apiKey, or apiUrl');

        // Act: Update webhook (no secret parameter)
        $this->webhookService->updateWebhook(
            self::TEST_WEBHOOK_ID,
            self::TEST_WEBHOOK_URL,
            ['*'],
        );
    }

    /**
     * @covers ::deleteWebhook
     */
    public function testDeleteWebhookThrowsExceptionWhenMissingConfig(): void
    {
        // Arrange: Missing API URL
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, null],
            ]);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Missing required configuration for webhook deleteWebhook');

        // Assert: Exception is thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required configuration: shopSlug, apiUsername, apiKey, or apiUrl');

        // Act: Delete webhook
        $this->webhookService->deleteWebhook(self::TEST_WEBHOOK_ID);
    }

    /**
     * @covers ::createWebhook
     */
    public function testCreateWebhookWithDebugMode(): void
    {
        // Arrange: Configure system config with debug mode enabled
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true],
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        // Expect debug logging calls
        $this->loggerMock->expects($this->exactly(3))
            ->method('debug');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')
            ->willReturn(201);
        $responseMock->method('toArray')
            ->willReturn([
                'uuid' => self::TEST_WEBHOOK_ID,
                'secret' => self::TEST_WEBHOOK_SECRET,
            ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Act: Create webhook (no secret parameter)
        $webhookData = $this->webhookService->createWebhook(
            self::TEST_WEBHOOK_URL,
            ['*'],
        );

        // Assert: Webhook data is returned
        $this->assertIsArray($webhookData);
        $this->assertEquals(self::TEST_WEBHOOK_ID, $webhookData['uuid']);
        $this->assertEquals(self::TEST_WEBHOOK_SECRET, $webhookData['secret']);
    }

    /**
     * @covers ::updateWebhook
     */
    public function testUpdateWebhookWithDebugMode(): void
    {
        // Arrange: Configure system config with debug mode enabled
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true],
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        // Expect debug logging calls
        $this->loggerMock->expects($this->exactly(3))
            ->method('debug');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')
            ->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Act: Update webhook (no secret parameter)
        $this->webhookService->updateWebhook(
            self::TEST_WEBHOOK_ID,
            self::TEST_WEBHOOK_URL,
            ['*'],
        );

        // Assert: No exception thrown
        $this->assertTrue(true);
    }

    /**
     * @covers ::deleteWebhook
     */
    public function testDeleteWebhookWithDebugMode(): void
    {
        // Arrange: Configure system config with debug mode enabled
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true],
                ['KarlaDelivery.config.shopSlug', null, self::TEST_SHOP_SLUG],
                ['KarlaDelivery.config.apiUsername', null, self::TEST_API_USERNAME],
                ['KarlaDelivery.config.apiKey', null, self::TEST_API_KEY],
                ['KarlaDelivery.config.apiUrl', null, self::TEST_API_URL],
            ]);

        // Expect debug logging calls
        $this->loggerMock->expects($this->exactly(3))
            ->method('debug');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')
            ->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Act: Delete webhook
        $this->webhookService->deleteWebhook(self::TEST_WEBHOOK_ID);

        // Assert: No exception thrown
        $this->assertTrue(true);
    }
}
