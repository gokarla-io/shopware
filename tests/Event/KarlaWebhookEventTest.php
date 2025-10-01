<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Event;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Karla\Delivery\Tests\Fixtures\KarlaWebhookPayloads;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Event\KarlaWebhookEvent
 */
final class KarlaWebhookEventTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::getName
     * @covers ::getEventGroup
     */
    public function testGetNameForShipmentEvent(): void
    {
        // Arrange: Shipment payload with event_group 'shipment_in_transit'
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert: event_group 'shipment_in_transit' becomes 'karla.shipment.in_transit'
        $this->assertEquals('karla.shipment.in_transit', $event->getName());
    }

    /**
     * @covers ::getName
     * @covers ::getEventGroup
     */
    public function testGetNameForClaimEvent(): void
    {
        // Arrange: Claim payload with event_group 'claim_created'
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::claim(), $context);

        // Assert: event_group 'claim_created' becomes 'karla.claim.created'
        $this->assertEquals('karla.claim.created', $event->getName());
    }

    /**
     * @covers ::getName
     * @covers ::getEventGroup
     */
    public function testGetNameThrowsExceptionWhenEventIdMissing(): void
    {
        // Arrange: Payload without event_group
        $webhookData = ['ref' => 'test', 'event_data' => []];
        $context = Context::createDefaultContext();

        // Act & Assert
        $event = new KarlaWebhookEvent($webhookData, $context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('event_group is required in webhook payload');
        $event->getName();
    }

    /**
     * @covers ::getEventGroup
     */
    public function testGetEventId(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertEquals('shipment_in_transit', $event->getEventGroup());
    }

    /**
     * @covers ::getContext
     */
    public function testGetContext(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertSame($context, $event->getContext());
    }

    /**
     * @covers ::getWebhookData
     */
    public function testGetWebhookData(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertEquals(KarlaWebhookPayloads::shipment(), $event->getWebhookData());
    }

    /**
     * @covers ::getRef
     */
    public function testGetRefFromShipmentPayload(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertEquals('shipments/in_transit/package_delivered', $event->getRef());
    }

    /**
     * @covers ::getRef
     */
    public function testGetRefFromClaimPayload(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::claim(), $context);

        // Assert
        $this->assertEquals('claims/created', $event->getRef());
    }

    /**
     * @covers ::getRef
     */
    public function testGetRefReturnsNullWhenMissing(): void
    {
        // Arrange
        $webhookData = ['event_data' => []];
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent($webhookData, $context);

        // Assert
        $this->assertNull($event->getRef());
    }

    /**
     * @covers ::getEventData
     */
    public function testGetEventDataForShipment(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);
        $eventData = $event->getEventData();

        // Assert
        $this->assertArrayHasKey('shipment_id', $eventData);
        $this->assertEquals('shipment-123', $eventData['shipment_id']);
        $this->assertArrayHasKey('tracking_number', $eventData);
        $this->assertEquals('TRACK123', $eventData['tracking_number']);
    }

    /**
     * @covers ::getEventData
     */
    public function testGetEventDataForClaim(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::claim(), $context);
        $eventData = $event->getEventData();

        // Assert
        $this->assertArrayHasKey('claim_id', $eventData);
        $this->assertEquals('claim-123', $eventData['claim_id']);
        $this->assertArrayHasKey('reason', $eventData);
        $this->assertEquals('damaged', $eventData['reason']);
    }

    /**
     * @covers ::getEventData
     */
    public function testGetEventDataReturnsEmptyArrayWhenMissing(): void
    {
        // Arrange
        $webhookData = ['ref' => 'test'];
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent($webhookData, $context);

        // Assert
        $this->assertEquals([], $event->getEventData());
    }

    /**
     * @covers ::getValues
     */
    public function testGetValuesExposesEventDataFieldsForShipment(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);
        $values = $event->getValues();

        // Assert: All data is nested under 'karla' key
        $this->assertArrayHasKey('karla', $values);
        $this->assertIsArray($values['karla']);

        // Assert: Shipment-specific fields are exposed (all from event_data)
        $this->assertArrayHasKey('shipment_id', $values['karla']);
        $this->assertEquals('shipment-123', $values['karla']['shipment_id']);
        $this->assertArrayHasKey('tracking_number', $values['karla']);
        $this->assertEquals('TRACK123', $values['karla']['tracking_number']);
        $this->assertArrayHasKey('carrier_reference', $values['karla']);
        $this->assertEquals('DHL', $values['karla']['carrier_reference']);
        $this->assertArrayHasKey('event_name', $values['karla']);
        $this->assertEquals('package_delivered', $values['karla']['event_name']);
    }

    /**
     * @covers ::getValues
     */
    public function testGetValuesExposesEventDataFieldsForClaim(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::claim(), $context);
        $values = $event->getValues();

        // Assert: All data is nested under 'karla' key
        $this->assertArrayHasKey('karla', $values);
        $this->assertIsArray($values['karla']);

        // Assert: Claim-specific fields are exposed (all from event_data)
        $this->assertArrayHasKey('claim_id', $values['karla']);
        $this->assertEquals('claim-123', $values['karla']['claim_id']);
        $this->assertArrayHasKey('reason', $values['karla']);
        $this->assertEquals('damaged', $values['karla']['reason']);
        $this->assertArrayHasKey('resolution_preference', $values['karla']);
        $this->assertEquals('refund', $values['karla']['resolution_preference']);
        $this->assertArrayHasKey('event_name', $values['karla']);
        $this->assertEquals('created', $values['karla']['event_name']);
    }

    /**
     * @covers ::getAvailableData
     */
    public function testGetAvailableData(): void
    {
        // Act
        $availableData = KarlaWebhookEvent::getAvailableData();

        // Assert
        $this->assertInstanceOf(EventDataCollection::class, $availableData);
    }

    /**
     * @covers ::getMailStruct
     */
    public function testGetMailStruct(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);
        $mailStruct = $event->getMailStruct();

        // Assert
        $this->assertInstanceOf(MailRecipientStruct::class, $mailStruct);

        // Assert: Customer email is included as recipient
        $recipients = $mailStruct->getRecipients();
        $this->assertArrayHasKey('customer@example.com', $recipients);
        $this->assertEquals('customer@example.com', $recipients['customer@example.com']);
    }

    /**
     * @covers ::getMailStruct
     */
    public function testGetMailStructReturnsEmptyWhenNoEmail(): void
    {
        // Arrange
        $context = Context::createDefaultContext();
        $webhookData = KarlaWebhookPayloads::shipment();
        unset($webhookData['context']['customer']['email']); // Remove email

        // Act
        $event = new KarlaWebhookEvent($webhookData, $context);
        $mailStruct = $event->getMailStruct();

        // Assert
        $this->assertInstanceOf(MailRecipientStruct::class, $mailStruct);
        $this->assertEmpty($mailStruct->getRecipients());
    }

    /**
     * @covers ::getSalesChannelId
     */
    public function testGetSalesChannelId(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertNull($event->getSalesChannelId());
    }

    /**
     * @covers ::getOrderId
     */
    public function testGetOrderId(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertEquals('order-456', $event->getOrderId());
    }

    /**
     * @covers ::getOrderId
     */
    public function testGetOrderIdThrowsExceptionWhenMissing(): void
    {
        // Arrange: Payload without order context
        $webhookData = ['event_group' => 'shipment_in_transit', 'event_data' => []];
        $context = Context::createDefaultContext();

        // Act & Assert
        $event = new KarlaWebhookEvent($webhookData, $context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order ID not found in webhook context. Required for OrderAware actions.');
        $event->getOrderId();
    }

    /**
     * @covers ::getOrderId
     */
    public function testGetOrderIdThrowsExceptionWhenNotString(): void
    {
        // Arrange: Payload with non-string order ID
        $webhookData = [
            'event_group' => 'shipment_in_transit',
            'event_data' => [],
            'context' => ['order' => ['external_id' => 123]], // numeric instead of string
        ];
        $context = Context::createDefaultContext();

        // Act & Assert
        $event = new KarlaWebhookEvent($webhookData, $context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order ID not found in webhook context. Required for OrderAware actions.');
        $event->getOrderId();
    }

    /**
     * @covers ::getCustomerId
     */
    public function testGetCustomerId(): void
    {
        // Arrange
        $context = Context::createDefaultContext();

        // Act
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);

        // Assert
        $this->assertEquals('customer-789', $event->getCustomerId());
    }

    /**
     * @covers ::getCustomerId
     */
    public function testGetCustomerIdThrowsExceptionWhenMissing(): void
    {
        // Arrange: Payload without customer context
        $webhookData = ['event_group' => 'shipment_in_transit', 'event_data' => []];
        $context = Context::createDefaultContext();

        // Act & Assert
        $event = new KarlaWebhookEvent($webhookData, $context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Customer ID not found in webhook context. Required for CustomerAware actions.');
        $event->getCustomerId();
    }

    /**
     * @covers ::getCustomerId
     */
    public function testGetCustomerIdThrowsExceptionWhenNotString(): void
    {
        // Arrange: Payload with non-string customer ID
        $webhookData = [
            'event_group' => 'shipment_in_transit',
            'event_data' => [],
            'context' => ['customer' => ['external_id' => 123]], // numeric instead of string
        ];
        $context = Context::createDefaultContext();

        // Act & Assert
        $event = new KarlaWebhookEvent($webhookData, $context);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Customer ID not found in webhook context. Required for CustomerAware actions.');
        $event->getCustomerId();
    }
}
