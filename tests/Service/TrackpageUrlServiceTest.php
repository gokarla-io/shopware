<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\TrackpageUrlService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class TrackpageUrlServiceTest extends TestCase
{
    private const SIGNED_URL = 'https://app.gokarla.io/track/test-shop?orderNumber=10001&token=sensitive-token';

    /** @var HttpClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private HttpClientInterface $httpClient;

    /** @var EntityRepository&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $orderRepository;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $logger;

    private TrackpageUrlService $service;

    protected function setUp(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['KarlaDelivery.config.apiUsername', null, 'api-user'],
            ['KarlaDelivery.config.apiKey', null, 'api-key'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.gokarla.io/'],
            ['KarlaDelivery.config.requestTimeout', null, 5.0],
        ]);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TrackpageUrlService(
            $config,
            $this->httpClient,
            $this->orderRepository,
            $this->logger,
        );
    }

    public function testStoresOnlySignedUrlWithoutOverwritingOtherCustomFields(): void
    {
        $order = $this->createOrder(['existing_field' => 'existing-value']);
        $context = Context::createDefaultContext();
        $loggedContext = null;
        $response = $this->createResponse(200, json_encode([
            'token' => 'sensitive-token',
            'url' => self::SIGNED_URL,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.gokarla.io/v1/shops/test-shop/orders/trackpage-url',
                $this->callback(function (array $options) use ($order): bool {
                    return $options['auth_basic'] === ['api-user', 'api-key']
                        && $options['json'] === ['id' => $order->getId(), 'id_type' => 'external_id']
                        && $options['timeout'] === 5.0;
                })
            )
            ->willReturn($response);
        $this->orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $payload) use ($order, $context): bool {
                    self::assertTrue($context->hasState(TrackpageUrlService::CONTEXT_STATE));

                    return $payload === [[
                        'id' => $order->getId(),
                        'versionId' => Defaults::LIVE_VERSION,
                        'customFields' => [
                            TrackpageUrlService::CUSTOM_FIELD_NAME => self::SIGNED_URL,
                        ],
                    ]];
                }),
                $context
            );
        $this->logger->expects($this->once())
            ->method('info')
            ->willReturnCallback(function (string $message, array $context) use (&$loggedContext): void {
                $loggedContext = [$message, $context];
            });

        $this->service->ensureForOrder($order, 'test-shop', $context);

        self::assertFalse($context->hasState(TrackpageUrlService::CONTEXT_STATE));
        self::assertStringNotContainsString('sensitive-token', json_encode($loggedContext, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SIGNED_URL, json_encode($loggedContext, JSON_THROW_ON_ERROR));
    }

    public function testDoesNothingWhenOrderAlreadyHasSignedUrl(): void
    {
        $order = $this->createOrder([TrackpageUrlService::CUSTOM_FIELD_NAME => self::SIGNED_URL]);

        $this->httpClient->expects($this->never())->method('request');
        $this->orderRepository->expects($this->never())->method('update');

        $this->service->ensureForOrder($order, 'test-shop', Context::createDefaultContext());
    }

    public function testMissingConfigurationDoesNotRequestSignedUrl(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);
        $service = new TrackpageUrlService(
            $config,
            $this->httpClient,
            $this->orderRepository,
            $this->logger,
        );
        $this->httpClient->expects($this->never())->method('request');
        $this->orderRepository->expects($this->never())->method('update');

        $service->ensureForOrder($this->createOrder(), '', Context::createDefaultContext());
    }

    public function testApiFailureDoesNotBlockOrderSync(): void
    {
        $loggedContext = null;
        $this->httpClient->method('request')->willReturn($this->createResponse(503, self::SIGNED_URL));
        $this->orderRepository->expects($this->never())->method('update');
        $this->logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(function (string $message, array $context) use (&$loggedContext): void {
                $loggedContext = [$message, $context];
            });

        $this->service->ensureForOrder($this->createOrder(), 'test-shop', Context::createDefaultContext());

        self::assertStringNotContainsString('sensitive-token', json_encode($loggedContext, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SIGNED_URL, json_encode($loggedContext, JSON_THROW_ON_ERROR));
    }

    public function testInvalidUrlIsNotStored(): void
    {
        $this->httpClient->method('request')->willReturn($this->createResponse(
            200,
            '{"url":"http://insecure.example.test/track?token=sensitive-token"}'
        ));
        $this->orderRepository->expects($this->never())->method('update');

        $this->service->ensureForOrder($this->createOrder(), 'test-shop', Context::createDefaultContext());
    }

    public function testRepositoryFailureRestoresContextState(): void
    {
        $context = Context::createDefaultContext();
        $this->httpClient->method('request')->willReturn($this->createResponse(
            200,
            json_encode(['url' => self::SIGNED_URL], JSON_THROW_ON_ERROR)
        ));
        $this->orderRepository->method('update')->willThrowException(new \RuntimeException('write failed'));

        $this->service->ensureForOrder($this->createOrder(), 'test-shop', $context);

        self::assertFalse($context->hasState(TrackpageUrlService::CONTEXT_STATE));
    }

    /** @param array<string, mixed> $customFields */
    private function createOrder(array $customFields = []): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('10001');
        $order->setCustomFields($customFields);

        return $order;
    }

    private function createResponse(int $statusCode, string $content): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->with(false)->willReturn($content);

        return $response;
    }
}
