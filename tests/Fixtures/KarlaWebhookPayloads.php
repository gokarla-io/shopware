<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Fixtures;

/**
 * Shared webhook payload fixtures for testing.
 */
final class KarlaWebhookPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function shipment(): array
    {
        return [
            'source' => 'shipments',
            'ref' => 'shipments/in_transit/package_delivered',
            'version' => 1,
            'triggered_at' => '2024-01-15T10:30:00Z',
            'event_group' => 'shipment_in_transit',
            'event_data' => [
                'shipment_id' => 'shipment-123',
                'carrier_reference' => 'DHL',
                'event_name' => 'package_delivered',
                'phase' => 'in_transit',
                'tracking_number' => 'TRACK123',
                'tracking_url' => 'https://tracking.example.com/TRACK123',
                'updated_at' => '2024-01-15T10:30:00Z',
            ],
            'context' => [
                'order' => [
                    'external_id' => 'order-456',
                    'external_customer_id' => 'customer-789',
                    'order_number' => '10001',
                ],
                'customer' => ['external_id' => 'customer-789', 'email' => 'customer@example.com'],
                'shipments' => [],
                'claims' => [],
            ],
            'shop_slug' => 'test-shop',
            'shop_id' => 'shop-789',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function claim(): array
    {
        return [
            'source' => 'claims',
            'ref' => 'claims/created',
            'version' => 1,
            'triggered_at' => '2024-01-15T10:30:00Z',
            'event_group' => 'claim_created',
            'event_data' => [
                'resolution_preference' => 'refund',
                'reason' => 'damaged',
                'status' => 'pending',
                'description' => 'Package arrived damaged',
                'customer_signature_image_url' => 'https://example.com/signature.png',
                'selected_items' => [
                    ['sku' => 'PROD-123', 'title' => 'Test Product', 'quantity' => 1, 'image_urls' => []],
                ],
                'image_urls' => ['https://example.com/damage.jpg'],
                'claim_id' => 'claim-123',
                'event_name' => 'created',
                'created_at' => '2024-01-15T10:30:00Z',
                'updated_at' => '2024-01-15T10:30:00Z',
            ],
            'context' => [
                'order' => [
                    'external_id' => 'order-456',
                    'external_customer_id' => 'customer-789',
                    'order_number' => '10001',
                ],
                'customer' => ['external_id' => 'customer-789', 'email' => 'customer@example.com'],
                'shipments' => [],
                'claims' => [],
            ],
            'shop_slug' => 'test-shop',
            'shop_id' => 'shop-789',
        ];
    }

    public static function shipmentJson(): string
    {
        return json_encode(self::shipment(), JSON_THROW_ON_ERROR);
    }

    public static function claimJson(): string
    {
        return json_encode(self::claim(), JSON_THROW_ON_ERROR);
    }
}
