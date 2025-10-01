<?php

declare(strict_types=1);

namespace Karla\Delivery\Event;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\CustomerAware;
use Shopware\Core\Framework\Event\EventData\EntityType;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Event\OrderAware;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a webhook is received from Karla API.
 * Creates specific events per event_group for Flow Builder.
 * Exposes all event_data fields dynamically for use in email templates.
 *
 * Event names in Flow Builder follow the pattern: karla.{source}.{event}
 * Examples: karla.shipment.delivered, karla.claim.created
 *
 * Implements OrderAware and CustomerAware to enable Flow Builder actions like:
 * - Set order state, add order tags, generate documents
 * - Add customer tags, change customer group, set custom fields
 */
class KarlaWebhookEvent extends Event implements FlowEventAware, MailAware, OrderAware, CustomerAware
{
    /**
     * All possible event groups that Karla backend can send.
     * These are the raw event_group values (without 'karla_' prefix).
     */
    public const EVENT_GROUPS = [
        // Claim events
        'claim_created',
        'claim_updated',

        // Shipment events
        'shipment_pre_transit',
        'shipment_in_transit',
        'shipment_damaged',
        'shipment_carrier_delay',
        'shipment_out_for_delivery',
        'shipment_delivery_failed',
        'shipment_delivery_failed_forwarded_to_parcel_shop',
        'shipment_delivery_failed_address_issue',
        'shipment_delivery_second_attempt',
        'shipment_delivered',
        'shipment_delivered_to_neighbour',
        'shipment_delivered_to_letterbox',
        'shipment_delivered_to_parcel_shop',
        'shipment_delivered_to_parcel_locker',
        'shipment_picked_up',
        'shipment_failed_returned',
        'shipment_refused_then_returned',
        'shipment_not_picked_up_then_returned',
        'shipment_delayed_due_to_customer_request',
        'shipment_delivered_all_events',
        'shipment_internal_trigger',
        'shipment_carrier_changed',
        'shipment_order_cancelled',
    ];

    // Transformed event name constants for Flow Builder (with 'karla.' prefix, first underscore becomes dot)
    public const EVENT_CLAIM_CREATED = 'karla.claim.created';
    public const EVENT_CLAIM_UPDATED = 'karla.claim.updated';
    public const EVENT_SHIPMENT_PRE_TRANSIT = 'karla.shipment.pre_transit';
    public const EVENT_SHIPMENT_IN_TRANSIT = 'karla.shipment.in_transit';
    public const EVENT_SHIPMENT_DAMAGED = 'karla.shipment.damaged';
    public const EVENT_SHIPMENT_CARRIER_DELAY = 'karla.shipment.carrier_delay';
    public const EVENT_SHIPMENT_OUT_FOR_DELIVERY = 'karla.shipment.out_for_delivery';
    public const EVENT_SHIPMENT_DELIVERY_FAILED = 'karla.shipment.delivery_failed';
    public const EVENT_SHIPMENT_DELIVERY_FAILED_FORWARDED_TO_PARCEL_SHOP = 'karla.shipment.delivery_failed_forwarded_to_parcel_shop';
    public const EVENT_SHIPMENT_DELIVERY_FAILED_ADDRESS_ISSUE = 'karla.shipment.delivery_failed_address_issue';
    public const EVENT_SHIPMENT_DELIVERY_SECOND_ATTEMPT = 'karla.shipment.delivery_second_attempt';
    public const EVENT_SHIPMENT_DELIVERED = 'karla.shipment.delivered';
    public const EVENT_SHIPMENT_DELIVERED_TO_NEIGHBOUR = 'karla.shipment.delivered_to_neighbour';
    public const EVENT_SHIPMENT_DELIVERED_TO_LETTERBOX = 'karla.shipment.delivered_to_letterbox';
    public const EVENT_SHIPMENT_DELIVERED_TO_PARCEL_SHOP = 'karla.shipment.delivered_to_parcel_shop';
    public const EVENT_SHIPMENT_DELIVERED_TO_PARCEL_LOCKER = 'karla.shipment.delivered_to_parcel_locker';
    public const EVENT_SHIPMENT_PICKED_UP = 'karla.shipment.picked_up';
    public const EVENT_SHIPMENT_FAILED_RETURNED = 'karla.shipment.failed_returned';
    public const EVENT_SHIPMENT_REFUSED_THEN_RETURNED = 'karla.shipment.refused_then_returned';
    public const EVENT_SHIPMENT_NOT_PICKED_UP_THEN_RETURNED = 'karla.shipment.not_picked_up_then_returned';
    public const EVENT_SHIPMENT_DELAYED_DUE_TO_CUSTOMER_REQUEST = 'karla.shipment.delayed_due_to_customer_request';
    public const EVENT_SHIPMENT_DELIVERED_ALL_EVENTS = 'karla.shipment.delivered_all_events';
    public const EVENT_SHIPMENT_INTERNAL_TRIGGER = 'karla.shipment.internal_trigger';
    public const EVENT_SHIPMENT_CARRIER_CHANGED = 'karla.shipment.carrier_changed';
    public const EVENT_SHIPMENT_ORDER_CANCELLED = 'karla.shipment.order_cancelled';


    /**
     * @param array<string, mixed> $webhookData
     */
    public function __construct(
        private readonly array $webhookData,
        private readonly Context $context,
    ) {
    }

    public static function getAvailableData(): EventDataCollection
    {
        // Define available data for Flow Builder
        // We expose eventGroup, order/customer entities, and all event_data fields dynamically
        return (new EventDataCollection())
            // Entity references for Flow Builder actions
            ->add('order', new EntityType(OrderDefinition::class))
            ->add('customer', new EntityType(CustomerDefinition::class))
            // Event data
            ->add('eventGroup', new ScalarValueType(ScalarValueType::TYPE_STRING))
            // Common shipment fields
            ->add('shipment_id', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('tracking_number', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('tracking_url', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('carrier_reference', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('phase', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('event_name', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('event_id', new ScalarValueType(ScalarValueType::TYPE_STRING))
            // Common claim fields
            ->add('claim_id', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('reason', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('status', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('description', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('resolution_preference', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    /**
     * Get the event name based on event_group from root payload.
     * Transforms event_group to Flow Builder event name.
     *
     * Event group format from backend: {source}_{event_name}
     * We transform underscores to dots and prepend 'karla.' for Shopware.
     *
     * Examples:
     * - 'shipment_in_transit' -> 'karla.shipment.in_transit'
     * - 'claim_created' -> 'karla.claim.created'
     */
    public function getName(): string
    {
        $eventGroup = $this->getEventGroup();

        if (! $eventGroup) {
            throw new \RuntimeException('event_group is required in webhook payload');
        }

        // Transform: 'shipment_in_transit' -> 'shipment.in_transit'
        // Split on first underscore: {source}_{event} -> {source}.{event}
        $eventName = implode('.', explode('_', $eventGroup, 2));

        // Prepend 'karla.' to get final event name
        return 'karla.' . $eventName;
    }

    /**
     * Get event_group from root payload.
     */
    public function getEventGroup(): ?string
    {
        return $this->webhookData['event_group'] ?? null;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookData(): array
    {
        return $this->webhookData;
    }

    /**
     * Get the ref field (e.g., 'shipments/in_transit/package_delivered').
     */
    public function getRef(): ?string
    {
        return $this->webhookData['ref'] ?? null;
    }

    /**
     * Get the event_data payload.
     *
     * @return array<string, mixed>
     */
    public function getEventData(): array
    {
        return $this->webhookData['event_data'] ?? [];
    }

    /**
     * Get values for Flow Builder.
     * Exposes all event_data fields dynamically under 'karla' namespace.
     * All Karla data is nested under 'karla' object for consistency with Shopware's pattern.
     *
     * @return array<string, scalar|array<mixed>|null>
     */
    public function getValues(): array
    {
        $eventData = $this->getEventData();

        // Build karla data object from event_data
        $karlaData = [];

        // Add all event_data fields dynamically
        // This allows templates to use {{ karla.tracking_number }}, {{ karla.event_group }}, etc.
        foreach ($eventData as $key => $value) {
            // Only expose scalar values and arrays to Flow Builder
            if (is_scalar($value) || is_array($value) || is_null($value)) {
                $karlaData[$key] = $value;
            }
        }

        // Return nested under 'karla' key for consistency with Shopware's pattern (order.*, customer.*)
        return [
            'karla' => $karlaData,
        ];
    }

    /**
     * Get default mail recipient for this event.
     *
     * This is used when Flow Builder's email action has recipient type set to "Default".
     * Returns the customer email from the webhook context.
     *
     * Note: If you configure the Flow Builder email action with recipient type "Customer",
     * Shopware will use the OrderCustomer email instead (via OrderAware interface),
     * which is preferred as it comes from the database rather than webhook payload.
     *
     * @return MailRecipientStruct
     */
    public function getMailStruct(): MailRecipientStruct
    {
        // Get customer email from webhook context
        $customerEmail = $this->webhookData['context']['customer']['email'] ?? null;

        if (! $customerEmail || ! is_string($customerEmail)) {
            // Return empty struct if no valid email in context
            return new MailRecipientStruct([]);
        }

        // Return mail struct with customer email as recipient
        // Format: [email => name], but we only have email so use it for both
        return new MailRecipientStruct([
            $customerEmail => $customerEmail,
        ]);
    }

    public function getSalesChannelId(): ?string
    {
        // No specific sales channel for webhook events
        return null;
    }

    /**
     * Get order ID from webhook context.
     * Karla webhooks include the Shopware order ID in context.order.external_id
     *
     * @throws \RuntimeException if order ID not found in webhook payload
     */
    public function getOrderId(): string
    {
        $orderId = $this->webhookData['context']['order']['external_id'] ?? null;

        if (! $orderId || ! is_string($orderId)) {
            throw new \RuntimeException('Order ID not found in webhook context. Required for OrderAware actions.');
        }

        return $orderId;
    }

    /**
     * Get customer ID from webhook context.
     * Karla webhooks include the customer ID in context.customer.external_id
     *
     * @throws \RuntimeException if customer ID not found in webhook payload
     */
    public function getCustomerId(): string
    {
        $customerId = $this->webhookData['context']['customer']['external_id'] ?? null;

        if (! $customerId || ! is_string($customerId)) {
            throw new \RuntimeException('Customer ID not found in webhook context. Required for CustomerAware actions.');
        }

        return $customerId;
    }
}
