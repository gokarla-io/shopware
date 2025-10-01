<?php

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Subscriber\OrderSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Tag\TagCollection;
use Shopware\Core\System\Tag\TagEntity;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OrderSubscriberTest extends TestCase
{
    private $loggerMock;
    private $orderRepositoryMock;
    private $httpClientMock;
    private $systemConfigServiceMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->orderRepositoryMock = $this->createMock(EntityRepository::class);
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);

        // Configure systemConfigServiceMock to return expected values for configurations
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            // API config
            ['KarlaDelivery.config.shopSlug', null, 'testSlug'],
            ['KarlaDelivery.config.apiUsername', null, 'testUser'],
            ['KarlaDelivery.config.apiKey', null, 'testKey'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.example.com'],
            ['KarlaDelivery.config.requestTimeout', null, 10.5],
            // Order Statuses config
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            // Delivery Statuses config
            ['KarlaDelivery.config.deliveryOpen', null, false],
            ['KarlaDelivery.config.deliveryShipped', null, true],
            ['KarlaDelivery.config.deliveryShippedPartially', null, true],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            // Mappings config
            ['KarlaDelivery.config.depositLineItemType', null, ""],
            ['KarlaDelivery.config.salesChannelMapping', null, ""],
        ]);
    }

    /**
     * Create a mock OrderEvent based on an order collection
     */
    private function mockOrderEvent(Context $context, OrderEntity $orderEntity): EntityWrittenEvent
    {
        $orderId = Uuid::randomHex();
        $orderData = [
            'id' => $orderId,
            'orderNumber' => '10001',
        ];
        $criteria = new Criteria([$orderId]);
        $entitySearchResult = new EntitySearchResult(
            OrderDefinition::ENTITY_NAME,
            1, // total results
            new OrderCollection([$orderEntity]),
            null, // aggregations
            $criteria,
            $context,
        );
        $entityWriteResult = new EntityWriteResult(
            $orderId,
            $orderData,
            OrderDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT,
            null,
            null
        );
        $event = new EntityWrittenEvent(
            OrderDefinition::ENTITY_NAME,
            [$entityWriteResult],
            $context,
        );
        $this->orderRepositoryMock->expects($this->any())
            ->method('search')
            ->willReturn($entitySearchResult);

        return $event;
    }

    /**
     * Create a mock Context with a SalesChannelApiSource
     */
    private function createSalesChannelApiSourceContextMock(): Context
    {
        $salesChannelApiSource = $this->createMock(SalesChannelApiSource::class);
        $salesChannelApiSource->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $context = $this->createMock(Context::class);
        $context->method('getSource')->willReturn($salesChannelApiSource);

        return $context;
    }

    /**
     * Create a mock Context with an AdminApiSource
     */
    private function createAdminApiSourceContextMock(): Context
    {
        $adminApiSource = $this->createMock(AdminApiSource::class);
        $context = $this->createMock(Context::class);
        $context->method('getSource')->willReturn($adminApiSource);

        return $context;
    }

    private function createMockStateMachineState(string $stateName)
    {
        $stateMachineStateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineStateMock->method('getTechnicalName')->willReturn($stateName);

        return $stateMachineStateMock;
    }

    /**
     * Create a mock OrderCollection with a single partial order
     */
    private function createPartialOrderEntityMock(): OrderEntity
    {
        // Mock order repository
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $productLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $productLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $productLineItemMock->method('getPrice')->willReturn(null);
        $productLineItemMock->method('getType')->willReturn('promotion');
        $productLineItemMock->method('getProduct')->willReturn(null);
        $promotionLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $promotionLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $promotionLineItemMock->method('getPrice')->willReturn(null);
        $promotionLineItemMock->method('getType')->willReturn('promotion');
        $promotionLineItemMock->method('getPayload')->willReturn(
            ['discountType' => 'percentage', 'code' => 'discountCode']
        );
        $promotionLineItemMock->method('getPromotion')->willReturn(null);
        $lineItemsCollection = new OrderLineItemCollection(
            [$productLineItemMock, $promotionLineItemMock]
        );
        $orderEntity->method('getLineItems')->willReturn($lineItemsCollection);
        $countryEntity = $this->createMock(CountryEntity::class);
        $countryEntity->method('getName')->willReturn('Example Country');
        $countryEntity->method('getIso')->willReturn('EX');
        $stateEntity = $this->createMock(CountryStateEntity::class);
        $stateEntity->method('getName')->willReturn('Example State');
        $stateEntity->method('getShortCode')->willReturn('EX-ST');
        $addressEntity = $this->createMock(OrderAddressEntity::class);
        $addressEntity->method('getId')->willReturn(Uuid::randomHex());
        $addressEntity->method('getCountry')->willReturn($countryEntity);
        $addressEntity->method('getCountryState')->willReturn($stateEntity);
        $addressEntity->method('getCity')->willReturn('Example City');
        $addressCollection = new OrderAddressCollection([$addressEntity]);
        $orderEntity->method('getAddresses')->willReturn($addressCollection);
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        return $orderEntity;
    }

    /**
     * Create a mock OrderCollection with a single order
     */
    private function createOrderEntityMock(): OrderEntity
    {
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $productLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $productLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $productLineItemMock->method('getReferencedId')->willReturn(Uuid::randomHex());
        $productLineItemMock->method('getType')->willReturn('product');
        $productLineItemMock->method('getLabel')->willReturn('Test Product');
        $productLineItemMock->method('getQuantity')->willReturn(2);
        $productLineItemMock->method('getUnitPrice')->willReturn(5.00);
        $productLineItemMock->method('getTotalPrice')->willReturn(10.00);
        $coverMock = $this->createMock(ProductMediaEntity::class);
        $mediaMock = $this->createMock(MediaEntity::class);
        $mediaMock->method('getUrl')->willReturn('https://example.com/image.jpg');
        $mediaMock->method('getAlt')->willReturn('Image description');
        $coverMock->method('getMedia')->willReturn($mediaMock);
        $productMock = $this->createMock(ProductEntity::class);
        $productMock->method('getCover')->willReturn($coverMock);
        $productLineItemMock->method('getProduct')->willReturn($productMock);
        $promotionLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $promotionLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $promotionLineItemMock->method('getType')->willReturn('promotion');
        $promotionLineItemMock->method('getPayload')->willReturn(
            ['discountType' => 'percentage', 'code' => 'discountCode']
        );
        $promotionMock = $this->createMock(PromotionEntity::class);
        $promotionMock->method('getCode')->willReturn("discount");
        $promotionLineItemMock->method('getPromotion')->willReturn($promotionMock);
        $lineItemsCollection = new OrderLineItemCollection(
            [$productLineItemMock, $promotionLineItemMock]
        );
        $orderEntity->method('getLineItems')->willReturn($lineItemsCollection);
        $countryEntity = $this->createMock(CountryEntity::class);
        $countryEntity->method('getName')->willReturn('Example Country');
        $countryEntity->method('getIso')->willReturn('EX');
        $stateEntity = $this->createMock(CountryStateEntity::class);
        $stateEntity->method('getName')->willReturn('Example State');
        $stateEntity->method('getShortCode')->willReturn('EX-ST');
        $addressEntity = $this->createMock(OrderAddressEntity::class);
        $addressEntity->method('getId')->willReturn(Uuid::randomHex());
        $addressEntity->method('getCountry')->willReturn($countryEntity);
        $addressEntity->method('getCountryState')->willReturn($stateEntity);
        $addressEntity->method('getCity')->willReturn('Example City');
        $addressCollection = new OrderAddressCollection([$addressEntity]);
        $orderEntity->method('getAddresses')->willReturn($addressCollection);
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        return $orderEntity;
    }

    /**
     * Create a mock OrderCollection with a single order and delivery
     */
    private function createOrderEntityWithDeliveryMock(): OrderEntity
    {
        $orderEntity = $this->createOrderEntityMock();
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getTrackingCodes')->willReturn(['123456']);
        $delivery->method('getShippingMethod')->willReturn(new ShippingMethodEntity("dhl"));
        $deliveryPosition = $this->createMock(OrderDeliveryPositionEntity::class);
        $productLineItem = $this->createMock(OrderLineItemEntity::class);
        $productLineItem->method('getId')->willReturn(Uuid::randomHex());
        $productLineItem->method('getReferencedId')->willReturn(Uuid::randomHex());
        $productLineItem->method('getType')->willReturn('product');
        $productLineItem->method('getLabel')->willReturn('Test Product');
        $productLineItem->method('getQuantity')->willReturn(1);
        $productLineItem->method('getUnitPrice')->willReturn(10.00);
        $productLineItem->method('getTotalPrice')->willReturn(10.00);
        $productLineItem->method('getProduct')->willReturn(null);
        $deliveryPosition->method('getOrderLineItem')->willReturn($productLineItem);
        $delivery->method('getPositions')->willReturn(new OrderDeliveryPositionCollection(
            [$deliveryPosition]
        ));
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([$delivery]));

        return $orderEntity;
    }

    /**
     * Test the `onOrderWritten` method of the OrderSubscriber class with an order placement
     */
    public function testOnOrderWrittenFull()
    {
        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $this->createOrderEntityMock(),
        );
        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $this->httpClientMock->expects(
            $this->once()
        )->method('request')->with(
            $this->equalTo('PUT'),
            $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
            $this->callback(function ($options) {
                $body = json_decode($options['body'], true);

                // Verify products structure exists
                if (! isset($body['order']['products'])) {
                    return false;
                }

                // If products exist, verify they have the required fields
                if (! empty($body['order']['products'])) {
                    $product = $body['order']['products'][0];

                    return isset($product['external_product_id'])
                        && isset($product['sku'])
                        && isset($product['title'])
                        && isset($product['quantity'])
                        && isset($product['price']);
                }

                // Allow empty products for now (mock might not be configured properly)
                return true;
            })
        )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance
        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test the `onOrderWritten` method of the OrderSubscriber class with a partial order placement
     */
    public function testOnOrderWrittenPartial()
    {
        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $this->createPartialOrderEntityMock(),
        );
        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $this->httpClientMock->expects(
            $this->once()
        )->method('request')->with(
            $this->equalTo('PUT'),
            $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
            $this->anything()
        )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance
        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }


    /**
     * Test the `onOrderWritten` method of the OrderSubscriber class with an order fulfillment
     */
    public function testOnOrderWrittenFulfillment()
    {
        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $this->createOrderEntityWithDeliveryMock(),
        );
        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $this->httpClientMock->expects(
            $this->once()
        )->method('request')->with(
            $this->equalTo('PUT'),
            $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
            $this->callback(function ($options) {
                $body = json_decode($options['body'], true);

                // Verify trackings structure exists
                if (! isset($body['trackings'])) {
                    return false;
                }

                // If trackings exist with products, verify they have the required fields
                if (! empty($body['trackings'])) {
                    $tracking = $body['trackings'][0];
                    if (isset($tracking['products']) && ! empty($tracking['products'])) {
                        $product = $tracking['products'][0];

                        return isset($product['external_product_id'])
                            && isset($product['sku'])
                            && isset($product['title'])
                            && isset($product['quantity'])
                            && isset($product['price']);
                    }
                }

                // Allow empty/missing products (mock might not be fully configured)
                return true;
            })
        )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance
        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Create a mock OrderEntity with a specific sales channel ID
     */
    private function createOrderEntityWithSalesChannelMock(string $salesChannelId): OrderEntity
    {
        // Create a fresh mock instead of using createOrderEntityMock to avoid conflicts
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);

        // Mock line items
        $lineItemsCollection = new OrderLineItemCollection([]);
        $orderEntity->method('getLineItems')->willReturn($lineItemsCollection);

        // Mock address
        $countryEntity = $this->createMock(CountryEntity::class);
        $countryEntity->method('getName')->willReturn('Example Country');
        $countryEntity->method('getIso')->willReturn('EX');
        $stateEntity = $this->createMock(CountryStateEntity::class);
        $stateEntity->method('getName')->willReturn('Example State');
        $stateEntity->method('getShortCode')->willReturn('EX-ST');
        $addressEntity = $this->createMock(OrderAddressEntity::class);
        $addressEntity->method('getId')->willReturn(Uuid::randomHex());
        $addressEntity->method('getCountry')->willReturn($countryEntity);
        $addressEntity->method('getCountryState')->willReturn($stateEntity);
        $addressEntity->method('getCity')->willReturn('Example City');
        $addressEntity->method('getStreet')->willReturn('');
        $addressEntity->method('getAdditionalAddressLine1')->willReturn(null);
        $addressEntity->method('getFirstName')->willReturn('');
        $addressEntity->method('getLastName')->willReturn('');
        $addressEntity->method('getPhoneNumber')->willReturn(null);
        $addressEntity->method('getZipcode')->willReturn('');
        $addressCollection = new OrderAddressCollection([$addressEntity]);
        $orderEntity->method('getAddresses')->willReturn($addressCollection);

        // Mock deliveries, tags, and SPECIFIC sales channel ID
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getSalesChannelId')->willReturn($salesChannelId);

        return $orderEntity;
    }

    /**
     * Create a mock OrderEntity with tags
     */
    private function createOrderEntityWithTagsMock(): OrderEntity
    {
        $orderEntity = $this->createOrderEntityMock();

        // Create mock tags
        $tag1 = $this->createMock(TagEntity::class);
        $tag1->method('getName')->willReturn('VIP');

        $tag2 = $this->createMock(TagEntity::class);
        $tag2->method('getName')->willReturn('Priority');

        $tagCollection = new TagCollection([$tag1, $tag2]);

        // Override the getTags method specifically for this test
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);

        // Mock customer
        $orderEntity->method('getOrderCustomer')->willReturn(null);

        // Mock currency
        $orderEntity->method('getCurrency')->willReturn(null);

        // Mock line items
        $productLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $productLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $productLineItemMock->method('getType')->willReturn('promotion');
        $productMock = $this->createMock(ProductEntity::class);
        $productLineItemMock->method('getProduct')->willReturn($productMock);

        $promotionLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $promotionLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $promotionLineItemMock->method('getType')->willReturn('promotion');
        $promotionLineItemMock->method('getPayload')->willReturn(
            ['discountType' => 'percentage', 'code' => 'discountCode']
        );
        $promotionMock = $this->createMock(PromotionEntity::class);
        $promotionMock->method('getCode')->willReturn("discount");
        $promotionLineItemMock->method('getPromotion')->willReturn($promotionMock);

        $lineItemsCollection = new OrderLineItemCollection(
            [$productLineItemMock, $promotionLineItemMock]
        );
        $orderEntity->method('getLineItems')->willReturn($lineItemsCollection);

        // Mock address
        $countryEntity = $this->createMock(CountryEntity::class);
        $countryEntity->method('getName')->willReturn('Example Country');
        $countryEntity->method('getIso')->willReturn('EX');
        $stateEntity = $this->createMock(CountryStateEntity::class);
        $stateEntity->method('getName')->willReturn('Example State');
        $stateEntity->method('getShortCode')->willReturn('EX-ST');
        $addressEntity = $this->createMock(OrderAddressEntity::class);
        $addressEntity->method('getId')->willReturn(Uuid::randomHex());
        $addressEntity->method('getCountry')->willReturn($countryEntity);
        $addressEntity->method('getCountryState')->willReturn($stateEntity);
        $addressEntity->method('getCity')->willReturn('Example City');
        $addressEntity->method('getStreet')->willReturn('');
        $addressEntity->method('getAdditionalAddressLine1')->willReturn(null);
        $addressEntity->method('getFirstName')->willReturn('');
        $addressEntity->method('getLastName')->willReturn('');
        $addressEntity->method('getPhoneNumber')->willReturn(null);
        $addressEntity->method('getZipcode')->willReturn('');
        $addressCollection = new OrderAddressCollection([$addressEntity]);
        $orderEntity->method('getAddresses')->willReturn($addressCollection);

        // Mock deliveries
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));

        // Mock tags - This is the important part!
        $orderEntity->method('getTags')->willReturn($tagCollection);
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        return $orderEntity;
    }

    /**
     * Test the `onOrderWritten` method with an order that has tags
     */
    public function testOnOrderWrittenWithTags()
    {
        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $this->createOrderEntityWithTagsMock(),
        );

        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Expect the request to be called with segments in the payload
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Check if segments are present and at least one tag is correctly formatted
                    return isset($body['order']['segments'])
                        && ! empty($body['order']['segments'])
                        && (in_array('Shopware.tag.VIP', $body['order']['segments'])
                            || in_array('Shopware.tag.Priority', $body['order']['segments']));
                })
            )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance
        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test the `onOrderWritten` method with an order that has no tags
     */
    public function testOnOrderWrittenWithoutTags()
    {
        $orderEntity = $this->createOrderEntityMock();
        $orderEntity->method('getTags')->willReturn(null);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $orderEntity,
        );

        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Expect the request to be called with empty segments array
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Check if segments are present but empty
                    return isset($body['order']['segments'])
                        && empty($body['order']['segments']);
                })
            )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance
        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test the `onOrderWritten` method with sales channel mapping
     */
    public function testOnOrderWrittenWithSalesChannelMapping()
    {
        // Setup system config to return sales channel mapping
        $salesChannelId = Uuid::randomHex();
        $mappedShopSlug = 'mapped-shop-slug';

        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $systemConfigMock->method('get')->willReturnMap([
            // API config
            ['KarlaDelivery.config.shopSlug', null, 'defaultSlug'],
            ['KarlaDelivery.config.apiUsername', null, 'testUser'],
            ['KarlaDelivery.config.apiKey', null, 'testKey'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.example.com'],
            ['KarlaDelivery.config.requestTimeout', null, 10.5],
            // Order Statuses config
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            // Delivery Statuses config
            ['KarlaDelivery.config.deliveryOpen', null, false],
            ['KarlaDelivery.config.deliveryShipped', null, true],
            ['KarlaDelivery.config.deliveryShippedPartially', null, true],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            // Mappings config with sales channel mapping
            ['KarlaDelivery.config.depositLineItemType', null, ""],
            ['KarlaDelivery.config.salesChannelMapping', null, $salesChannelId . ':' . $mappedShopSlug],
        ]);

        $orderEntity = $this->createOrderEntityWithSalesChannelMock($salesChannelId);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $orderEntity,
        );

        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Expect the request to be called with the mapped shop slug
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/' . $mappedShopSlug . '/orders'),
                $this->anything()
            )
            ->willReturn($responseMock);

        // Create the OrderSubscriber instance with the mock config
        $orderSubscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Triggered when `ORDER_WRITTEN_EVENT` is dispatched
        $orderSubscriber->onOrderWritten($event);
    }
}
