<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Karla\Delivery\Subscriber\BusinessEventCollectorSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Shopware\Core\Framework\Event\BusinessEventCollectorResponse;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Subscriber\BusinessEventCollectorSubscriber
 */
final class BusinessEventCollectorSubscriberTest extends TestCase
{
    /**
     * @covers ::getSubscribedEvents
     */
    public function testGetSubscribedEvents(): void
    {
        $events = BusinessEventCollectorSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(BusinessEventCollectorEvent::NAME, $events);
        $this->assertEquals('onCollectBusinessEvents', $events[BusinessEventCollectorEvent::NAME]);
    }

    /**
     * @covers ::__construct
     */
    public function testConstructor(): void
    {
        // Arrange
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Act
        $subscriber = new BusinessEventCollectorSubscriber($systemConfigService, $logger);

        // Assert
        $this->assertInstanceOf(BusinessEventCollectorSubscriber::class, $subscriber);
    }

    /**
     * @covers ::onCollectBusinessEvents
     */
    public function testOnCollectBusinessEventsRegistersAllEvents(): void
    {
        // Arrange
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->with('KarlaDelivery.config.debugMode')
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');
        $logger->expects($this->never())->method('info');

        $collection = new BusinessEventCollectorResponse();
        $context = Context::createDefaultContext();
        $event = new BusinessEventCollectorEvent($collection, $context);

        $subscriber = new BusinessEventCollectorSubscriber($systemConfigService, $logger);

        // Act
        $subscriber->onCollectBusinessEvents($event);

        // Assert: All 26 events should be registered
        $this->assertCount(count(KarlaWebhookEvent::EVENT_GROUPS), $collection);

        // Assert: Check specific events are registered with technical names
        $this->assertTrue($collection->has('karla.claim.created'));
        $this->assertTrue($collection->has('karla.claim.updated'));
        $this->assertTrue($collection->has('karla.shipment.delivered'));
        $this->assertTrue($collection->has('karla.shipment.in_transit'));

        // Assert: Check event definition structure
        $definition = $collection->get('karla.shipment.delivered');
        $this->assertEquals('karla.shipment.delivered', $definition->getName());
        $this->assertEquals(KarlaWebhookEvent::class, $definition->getClass());
    }

    /**
     * @covers ::onCollectBusinessEvents
     */
    public function testOnCollectBusinessEventsWithDebugMode(): void
    {
        // Arrange
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->with('KarlaDelivery.config.debugMode')
            ->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);

        // Expect debug logging: once for "Starting", then once for each event (25 events)
        $logger->expects($this->exactly(26))
            ->method('debug');

        // Expect info logging for completion
        $logger->expects($this->once())
            ->method('info')
            ->with('Flow Builder event registration completed', $this->callback(function ($context) {
                return $context['component'] === 'flow.builder'
                    && $context['registered'] === count(KarlaWebhookEvent::EVENT_GROUPS)
                    && $context['failed'] === 0
                    && $context['total'] === count(KarlaWebhookEvent::EVENT_GROUPS);
            }));

        $collection = new BusinessEventCollectorResponse();
        $context = Context::createDefaultContext();
        $event = new BusinessEventCollectorEvent($collection, $context);

        $subscriber = new BusinessEventCollectorSubscriber($systemConfigService, $logger);

        // Act
        $subscriber->onCollectBusinessEvents($event);

        // Assert: All events registered
        $this->assertCount(count(KarlaWebhookEvent::EVENT_GROUPS), $collection);
    }

    /**
     * @covers ::onCollectBusinessEvents
     */
    public function testOnCollectBusinessEventsTransformsEventNamesCorrectly(): void
    {
        // Arrange
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);

        $collection = new BusinessEventCollectorResponse();
        $context = Context::createDefaultContext();
        $event = new BusinessEventCollectorEvent($collection, $context);

        $subscriber = new BusinessEventCollectorSubscriber($systemConfigService, $logger);

        // Act
        $subscriber->onCollectBusinessEvents($event);

        // Assert: Verify events are registered with technical names (only first underscore replaced)
        $this->assertTrue($collection->has('karla.shipment.delivery_failed_forwarded_to_parcel_shop'));
        $this->assertTrue($collection->has('karla.shipment.not_picked_up_then_returned'));
        $this->assertTrue($collection->has('karla.shipment.delayed_due_to_customer_request'));

        // Assert: Simple events are also registered correctly
        $this->assertTrue($collection->has('karla.claim.created'));
        $this->assertTrue($collection->has('karla.shipment.delivered'));
    }

    /**
     * @covers ::onCollectBusinessEvents
     */
    public function testOnCollectBusinessEventsHandlesExceptions(): void
    {
        // Arrange
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->with('KarlaDelivery.config.debugMode')
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);

        // Create a mock collection that throws an exception on set()
        $collection = $this->createMock(BusinessEventCollectorResponse::class);
        $collection->method('set')
            ->willThrowException(new \RuntimeException('Test exception'));

        // Expect error logging for each failed event
        $logger->expects($this->exactly(count(KarlaWebhookEvent::EVENT_GROUPS)))
            ->method('error')
            ->with('Exception while registering Flow Builder event', $this->callback(function ($context) {
                return $context['component'] === 'flow.builder'
                    && isset($context['event_group'])
                    && isset($context['event_name'])
                    && $context['error'] === 'Test exception';
            }));

        $context = Context::createDefaultContext();
        $event = new BusinessEventCollectorEvent($collection, $context);

        $subscriber = new BusinessEventCollectorSubscriber($systemConfigService, $logger);

        // Act - should not throw exception
        $subscriber->onCollectBusinessEvents($event);

        // Assert: Method completes without throwing
        $this->assertTrue(true);
    }
}
