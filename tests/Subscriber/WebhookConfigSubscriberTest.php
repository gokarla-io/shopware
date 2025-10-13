<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Service\WebhookService;
use Karla\Delivery\Subscriber\WebhookConfigSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Subscriber\WebhookConfigSubscriber
 */
final class WebhookConfigSubscriberTest extends TestCase
{
    /** @var WebhookService&\PHPUnit\Framework\MockObject\MockObject */
    private WebhookService $webhookServiceMock;

    /** @var SystemConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private SystemConfigService $systemConfigServiceMock;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $loggerMock;

    /** @var MessageBusInterface&\PHPUnit\Framework\MockObject\MockObject */
    private MessageBusInterface $messageBusMock;

    private WebhookConfigSubscriber $subscriber;

    // Test constants
    private const TEST_BASE_URL = 'https://shop.example.com';
    private const TEST_WEBHOOK_URL = 'https://shop.example.com/api/karla/webhooks/abc123';
    private const TEST_WEBHOOK_SECRET = 'test-secret-uuid';
    private const TEST_WEBHOOK_ID = 'webhook-id-123';

    protected function setUp(): void
    {
        $this->webhookServiceMock = $this->createMock(WebhookService::class);
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->messageBusMock = $this->createMock(MessageBusInterface::class);

        $this->subscriber = new WebhookConfigSubscriber(
            $this->webhookServiceMock,
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->messageBusMock,
            self::TEST_BASE_URL,
        );
    }

    /**
     * @covers ::getSubscribedEvents
     */
    public function testGetSubscribedEvents(): void
    {
        // Act
        $events = WebhookConfigSubscriber::getSubscribedEvents();

        // Assert
        $this->assertArrayHasKey(SystemConfigChangedEvent::class, $events);
        $this->assertEquals('onSystemConfigChanged', $events[SystemConfigChangedEvent::class]);
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testCreatesWebhookWhenEnabled(): void
    {
        // Arrange: Webhook enabled changed from false to true
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookUrl', null, null],
                ['KarlaDelivery.config.webhookSecret', null, null],
                ['KarlaDelivery.config.webhookId', null, null],
                ['KarlaDelivery.config.webhookEnabledEvents', null, '*'],
            ]);

        $this->webhookServiceMock->expects($this->once())
            ->method('generateWebhookUrl')
            ->with(self::TEST_BASE_URL)
            ->willReturn(self::TEST_WEBHOOK_URL);

        $this->webhookServiceMock->expects($this->once())
            ->method('createWebhook')
            ->with(self::TEST_WEBHOOK_URL, ['*'])
            ->willReturn([
                'uuid' => self::TEST_WEBHOOK_ID,
                'secret' => self::TEST_WEBHOOK_SECRET,
            ]);

        $this->systemConfigServiceMock->expects($this->exactly(3))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value): void {
                static $callCount = 0;
                ++$callCount;

                if ($callCount === 1) {
                    $this->assertEquals('KarlaDelivery.config.webhookUrl', $key);
                    $this->assertEquals(self::TEST_WEBHOOK_URL, $value);
                } elseif ($callCount === 2) {
                    $this->assertEquals('KarlaDelivery.config.webhookSecret', $key);
                    $this->assertEquals(self::TEST_WEBHOOK_SECRET, $value);
                } elseif ($callCount === 3) {
                    $this->assertEquals('KarlaDelivery.config.webhookId', $key);
                    $this->assertEquals(self::TEST_WEBHOOK_ID, $value);
                }
            });

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testDeletesWebhookWhenDisabled(): void
    {
        // Arrange: Webhook disabled
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', false, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, false],
                ['KarlaDelivery.config.webhookId', null, self::TEST_WEBHOOK_ID],
            ]);

        $this->webhookServiceMock->expects($this->once())
            ->method('deleteWebhook')
            ->with(self::TEST_WEBHOOK_ID);

        $this->systemConfigServiceMock->expects($this->exactly(3))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value): void {
                static $callCount = 0;
                ++$callCount;

                if ($callCount === 1) {
                    $this->assertEquals('KarlaDelivery.config.webhookUrl', $key);
                    $this->assertNull($value);
                } elseif ($callCount === 2) {
                    $this->assertEquals('KarlaDelivery.config.webhookSecret', $key);
                    $this->assertNull($value);
                } elseif ($callCount === 3) {
                    $this->assertEquals('KarlaDelivery.config.webhookId', $key);
                    $this->assertNull($value);
                }
            });

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testIgnoresWebhookEnabledEventsChangedAfterCreation(): void
    {
        // Arrange: Enabled events changed after webhook already created
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabledEvents', 'shipments/delivered,claims/created', null);

        // Changes to enabled events AFTER webhook creation are ignored
        // The handler is commented out, so updateWebhook should NOT be called
        $this->webhookServiceMock->expects($this->never())
            ->method('updateWebhook');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: No webhook update occurs (changes after creation are ignored)
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testIgnoresUnrelatedConfigChanges(): void
    {
        // Arrange: Unrelated config change
        $event = new SystemConfigChangedEvent('SomeOtherPlugin.config.someSetting', 'value', null);

        $this->webhookServiceMock->expects($this->never())
            ->method($this->anything());

        $this->systemConfigServiceMock->expects($this->never())
            ->method('set');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testSkipsWebhookCreationWhenAlreadyExists(): void
    {
        // Arrange: Webhook already exists (webhookId is set)
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookId', null, self::TEST_WEBHOOK_ID],
            ]);

        $this->webhookServiceMock->expects($this->never())
            ->method('createWebhook');

        $this->systemConfigServiceMock->expects($this->never())
            ->method('set');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testSkipsWebhookDeletionWhenNoWebhookId(): void
    {
        // Arrange: Webhook disabled but no webhookId
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', false, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, false],
                ['KarlaDelivery.config.webhookId', null, null],
            ]);

        $this->webhookServiceMock->expects($this->never())
            ->method('deleteWebhook');

        $this->systemConfigServiceMock->expects($this->never())
            ->method('set');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesErrorDuringWebhookCreation(): void
    {
        // Arrange: Webhook creation fails
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookUrl', null, null],
                ['KarlaDelivery.config.webhookSecret', null, null],
                ['KarlaDelivery.config.webhookId', null, null],
                ['KarlaDelivery.config.webhookEnabledEvents', null, '*'],
            ]);

        $this->webhookServiceMock->method('generateWebhookUrl')
            ->willReturn(self::TEST_WEBHOOK_URL);

        $this->webhookServiceMock->method('createWebhook')
            ->willThrowException(new \RuntimeException('API Error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to create webhook on config change', $this->anything());

        // Expect webhook toggle to be disabled after creation failure
        $this->systemConfigServiceMock->expects($this->once())
            ->method('set')
            ->with('KarlaDelivery.config.webhookEnabled', false, null);

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesErrorDuringWebhookDeletion(): void
    {
        // Arrange: Webhook deletion fails
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', false, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, false],
                ['KarlaDelivery.config.webhookId', null, self::TEST_WEBHOOK_ID],
            ]);

        $this->webhookServiceMock->method('deleteWebhook')
            ->willThrowException(new \RuntimeException('API Error'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Failed to delete webhook on config change', $this->anything());

        // Expect config to be cleared even after deletion failure
        $this->systemConfigServiceMock->expects($this->exactly(3))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value): void {
                static $callCount = 0;
                ++$callCount;

                if ($callCount === 1) {
                    $this->assertEquals('KarlaDelivery.config.webhookUrl', $key);
                    $this->assertNull($value);
                } elseif ($callCount === 2) {
                    $this->assertEquals('KarlaDelivery.config.webhookSecret', $key);
                    $this->assertNull($value);
                } elseif ($callCount === 3) {
                    $this->assertEquals('KarlaDelivery.config.webhookId', $key);
                    $this->assertNull($value);
                }
            });

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testParsesEnabledEventsAsArrayDuringCreation(): void
    {
        // Arrange: Create webhook with custom events (tests getEnabledEventsArray parsing)
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookUrl', null, null],
                ['KarlaDelivery.config.webhookSecret', null, null],
                ['KarlaDelivery.config.webhookId', null, null],
                ['KarlaDelivery.config.webhookEnabledEvents', null, 'event1, event2 , event3'], // With spaces
            ]);

        $this->webhookServiceMock->method('generateWebhookUrl')
            ->willReturn(self::TEST_WEBHOOK_URL);

        $this->webhookServiceMock->expects($this->once())
            ->method('createWebhook')
            ->with(
                $this->anything(),
                ['event1', 'event2', 'event3'], // Trimmed array
            )
            ->willReturn([
                'uuid' => self::TEST_WEBHOOK_ID,
                'secret' => self::TEST_WEBHOOK_SECRET,
            ]);

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks - events are properly trimmed
    }


    /**
     * @covers ::onSystemConfigChanged
     */
    public function testCreatesWebhookWithDebugMode(): void
    {
        // Arrange: Webhook enabled with debug mode
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true],
                ['KarlaDelivery.config.webhookEnabled', null, true],
                ['KarlaDelivery.config.webhookUrl', null, null],
                ['KarlaDelivery.config.webhookSecret', null, null],
                ['KarlaDelivery.config.webhookId', null, null],
                ['KarlaDelivery.config.webhookEnabledEvents', null, '*'],
            ]);

        $this->webhookServiceMock->method('generateWebhookUrl')
            ->willReturn(self::TEST_WEBHOOK_URL);

        $this->webhookServiceMock->method('createWebhook')
            ->willReturn([
                'uuid' => self::TEST_WEBHOOK_ID,
                'secret' => self::TEST_WEBHOOK_SECRET,
            ]);

        // Expect debug logging calls
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('debug');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testDeletesWebhookWithDebugMode(): void
    {
        // Arrange: Webhook disabled with debug mode
        $event = new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', false, null);

        $this->systemConfigServiceMock->method('get')
            ->willReturnMap([
                ['KarlaDelivery.config.debugMode', null, true],
                ['KarlaDelivery.config.webhookEnabled', null, false],
                ['KarlaDelivery.config.webhookId', null, self::TEST_WEBHOOK_ID],
            ]);

        $this->webhookServiceMock->expects($this->once())
            ->method('deleteWebhook')
            ->with(self::TEST_WEBHOOK_ID);

        // Expect debug logging calls
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('debug');

        // Act
        $this->subscriber->onSystemConfigChanged($event);

        // Assert: Expectations verified by mocks
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesProductSyncEnabledFirstTime(): void
    {
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncLastEnabled', null, null], // Never enabled before
        ]);

        $this->systemConfigServiceMock->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function ($key, $value) {
                if ($key === 'KarlaDelivery.config.productSyncLastEnabled') {
                    $this->assertIsInt($value);
                } elseif ($key === 'KarlaDelivery.config.productSyncStatus') {
                    $this->assertEquals('running', $value);
                }

                return null;
            });

        $this->messageBusMock->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof \Karla\Delivery\Message\SyncAllProductsMessage
                    && $message->getOffset() === 0
                    && $message->getLimit() === 50;
            }))
            ->willReturnCallback(function ($message) {
                return new \Symfony\Component\Messenger\Envelope($message);
            });

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Product sync enabled - triggering full sync', $this->callback(function ($context) {
                return $context['component'] === 'product.config'
                    && $context['last_enabled'] === 'never';
            }));

        $event = new SystemConfigChangedEvent(
            'KarlaDelivery.config.productSyncEnabled',
            true,
            null
        );

        $this->subscriber->onSystemConfigChanged($event);
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesProductSyncEnabledRecentlyEnabledSkipsSync(): void
    {
        $recentTime = time() - 120; // 2 minutes ago (within 5-minute cooldown)

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncLastEnabled', null, $recentTime],
        ]);

        // Should not set config or dispatch message
        $this->systemConfigServiceMock->expects($this->never())->method('set');
        $this->messageBusMock->expects($this->never())->method('dispatch');

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Product sync enabled - skipping full sync (recently synced)', $this->callback(function ($context) {
                return $context['component'] === 'product.config'
                    && isset($context['last_enabled'])
                    && isset($context['seconds_ago'])
                    && isset($context['cooldown_remaining']);
            }));

        $event = new SystemConfigChangedEvent(
            'KarlaDelivery.config.productSyncEnabled',
            true,
            null
        );

        $this->subscriber->onSystemConfigChanged($event);
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesProductSyncEnabledAfterCooldown(): void
    {
        $oldTime = time() - 600; // 10 minutes ago (past 5-minute cooldown)

        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.productSyncLastEnabled', null, $oldTime],
        ]);

        $this->systemConfigServiceMock->expects($this->exactly(2))
            ->method('set');

        $this->messageBusMock->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($message) {
                return new \Symfony\Component\Messenger\Envelope($message);
            });

        $event = new SystemConfigChangedEvent(
            'KarlaDelivery.config.productSyncEnabled',
            true,
            null
        );

        $this->subscriber->onSystemConfigChanged($event);
    }

    /**
     * @covers ::onSystemConfigChanged
     */
    public function testHandlesProductSyncDisabled(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Product sync disabled', [
                'component' => 'product.config',
            ]);

        // Should not set config or dispatch
        $this->systemConfigServiceMock->expects($this->never())->method('set');
        $this->messageBusMock->expects($this->never())->method('dispatch');

        $event = new SystemConfigChangedEvent(
            'KarlaDelivery.config.productSyncEnabled',
            false,
            null
        );

        $this->subscriber->onSystemConfigChanged($event);
    }
}
