<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Controller\Api;

use Karla\Delivery\Controller\Api\WebhookController;
use Karla\Delivery\Event\KarlaWebhookEvent;
use Karla\Delivery\Tests\Fixtures\KarlaWebhookPayloads;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Controller\Api\WebhookController
 */
final class WebhookControllerTest extends TestCase
{
    /** @var SystemConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private SystemConfigService $systemConfigServiceMock;

    /** @var EventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventDispatcherInterface $eventDispatcherMock;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $loggerMock;

    private WebhookController $webhookController;

    // Test configuration constants
    private const TEST_SECRET = 'test-webhook-secret-uuid';
    protected function setUp(): void
    {
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->webhookController = new WebhookController(
            $this->systemConfigServiceMock,
            $this->eventDispatcherMock,
            $this->loggerMock,
        );
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookSuccessfully(): void
    {
        // Arrange: Configure system config for enabled webhook
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $signature = $this->generateValidSignature(KarlaWebhookPayloads::shipmentJson(), self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            KarlaWebhookPayloads::shipmentJson(),
        );

        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(KarlaWebhookEvent::class));

        // Expect metrics logging (always enabled)
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Webhook trigger received', $this->callback(function ($context) {
                return isset($context['component']) && $context['component'] === 'webhook.metrics'
                    && isset($context['event_name'])
                    && isset($context['event_group']);
            }));

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Success response
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('success', $responseData['status']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleClaimWebhookSuccessfully(): void
    {
        // Arrange: Configure system config for enabled webhook
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $signature = $this->generateValidSignature(KarlaWebhookPayloads::claimJson(), self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            KarlaWebhookPayloads::claimJson(),
        );

        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(KarlaWebhookEvent::class));

        // Expect metrics logging (always enabled)
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Webhook trigger received', $this->callback(function ($context) {
                return $context['component'] === 'webhook.metrics'
                    && $context['event_name'] === 'karla.claim.created';
            }));

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Success response
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('success', $responseData['status']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenWebhookDisabled(): void
    {
        // Arrange: Webhook disabled
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, false],
            ]);

        $request = new Request([], [], [], [], [], [], KarlaWebhookPayloads::shipmentJson());

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Webhook receiver is disabled');

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Forbidden response
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Webhook receiver is disabled', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenSignatureMissing(): void
    {
        // Arrange: Webhook enabled but no signature header
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $request = new Request([], [], [], [], [], [], KarlaWebhookPayloads::shipmentJson());

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Webhook signature missing');

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Unauthorized response
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Invalid signature', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenSignatureInvalid(): void
    {
        // Arrange: Webhook enabled with invalid signature
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => 't=' . time() . ',v1=invalid_signature'],
            KarlaWebhookPayloads::shipmentJson(),
        );

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Invalid webhook signature');

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Unauthorized response
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Invalid signature', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenTimestampTooOld(): void
    {
        // Arrange: Signature with old timestamp (>5 minutes)
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $oldTimestamp = time() - 301; // 5 minutes + 1 second ago
        $signature = $this->generateValidSignature(KarlaWebhookPayloads::shipmentJson(), self::TEST_SECRET, $oldTimestamp);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            KarlaWebhookPayloads::shipmentJson(),
        );

        $this->loggerMock->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->logicalOr(
                'Webhook timestamp too old',
                'Invalid webhook signature',
            ));

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Unauthorized response
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Invalid signature', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenPayloadInvalid(): void
    {
        // Arrange: Valid signature but invalid JSON payload
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $invalidPayload = 'invalid json{';
        $signature = $this->generateValidSignature($invalidPayload, self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            $invalidPayload,
        );

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Invalid webhook payload', $this->anything());

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Bad request response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Invalid payload', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorWhenEventDispatchFails(): void
    {
        // Arrange: Valid webhook but event dispatch throws exception
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $signature = $this->generateValidSignature(KarlaWebhookPayloads::shipmentJson(), self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            KarlaWebhookPayloads::shipmentJson(),
        );

        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \Exception('Event dispatch failed'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to process webhook', $this->anything());

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Internal server error response
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('Failed to process webhook', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookWithEmptyPayload(): void
    {
        // Arrange: Valid signature but empty payload
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $emptyPayload = '';
        $signature = $this->generateValidSignature($emptyPayload, self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            $emptyPayload,
        );

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Invalid webhook payload', $this->anything());

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Bad request response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookWithMalformedSignatureHeader(): void
    {
        // Arrange: Malformed signature header
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => 'malformed_signature'],
            KarlaWebhookPayloads::shipmentJson(),
        );

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Invalid webhook signature');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Unauthorized response
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookReturnsErrorForMissingEventGroup(): void
    {
        // Arrange: Valid webhook but missing event_group
        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        $payloadWithoutEventGroup = '{"source":"unknown","ref":"unknown/test","version":1,"triggered_at":"2024-01-15T10:30:00Z","event_data":{},"context":{},"shop_slug":"test-shop","shop_id":"shop-789"}';
        $signature = $this->generateValidSignature($payloadWithoutEventGroup, self::TEST_SECRET, time());

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_KARLA_SIGNATURE' => $signature],
            $payloadWithoutEventGroup,
        );

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('event_group missing from webhook payload', $this->anything());

        $this->eventDispatcherMock->expects($this->never())
            ->method('dispatch');

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Bad request response
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals('error', $responseData['status']);
        $this->assertEquals('event_group is required', $responseData['message']);
    }

    /**
     * @covers ::handleWebhook
     */
    public function testHandleWebhookWithDebugMode(): void
    {
        // Arrange: Enable debug mode
        $payload = KarlaWebhookPayloads::shipmentJson();
        $timestamp = time();
        $signature = $this->generateValidSignature($payload, self::TEST_SECRET, $timestamp);

        $request = new Request([], [], [], [], [], [], $payload);
        $request->headers->set('Karla-Signature', $signature);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true], // Debug mode enabled
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookSecret', null, self::TEST_SECRET],
            ]);

        // Expect debug logging calls
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('debug');

        // Expect info logging calls (metrics + success)
        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->with($this->logicalOr(
                $this->equalTo('Webhook trigger received'),
                $this->equalTo('Webhook processed successfully')
            ));

        // Act: Handle webhook
        $response = $this->webhookController->handleWebhook($request, 'test-webhook-id', Context::createDefaultContext());

        // Assert: Success response
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Generate a valid HMAC-SHA256 signature for testing.
     */
    private function generateValidSignature(string $payload, string $secret, int $timestamp): string
    {
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return 't=' . $timestamp . ',v1=' . $signature;
    }
}
