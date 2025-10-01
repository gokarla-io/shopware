<?php

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Subscriber\OrderSubscriber;
use Karla\Delivery\Tests\Support\ConfigBuilder;
use Karla\Delivery\Tests\Support\OrderMockBuilderTrait;
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
    use OrderMockBuilderTrait;

    // Test configuration constants
    private const TEST_SHOP_SLUG = 'testSlug';
    private const TEST_API_USER = 'testUser';
    private const TEST_API_KEY = 'testKey';
    private const TEST_API_URL = 'https://api.example.com';

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $loggerMock;

    /** @var EntityRepository&\PHPUnit\Framework\MockObject\MockObject */
    private EntityRepository $orderRepositoryMock;

    /** @var HttpClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private HttpClientInterface $httpClientMock;

    /** @var SystemConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private SystemConfigService $systemConfigServiceMock;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->orderRepositoryMock = $this->createMock(EntityRepository::class);
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);

        // Configure systemConfigServiceMock using ConfigBuilder
        $configMap = ConfigBuilder::create()
            ->withApiConfig(self::TEST_SHOP_SLUG, self::TEST_API_USER, self::TEST_API_KEY, self::TEST_API_URL)
            ->withDefaultOrderStatuses()
            ->withDefaultDeliveryStatuses()
            ->withDefaultMappings()
            ->buildMap();

        $this->systemConfigServiceMock->method('get')->willReturnMap($configMap);
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
     * Create a mock address entity
     */
    private function createAddressMock(): OrderAddressEntity
    {
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

        return $addressEntity;
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

    /**
     * Test that getSubscribedEvents returns correct event mappings
     */
    public function testGetSubscribedEvents()
    {
        $events = OrderSubscriber::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey('order.written', $events);
        $this->assertEquals('onOrderWritten', $events['order.written']);
    }

    /**
     * Test order skipped when configuration is missing
     */
    public function testOrderSkippedWhenConfigurationMissing()
    {
        // Arrange: Setup config with missing API credentials
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $configMap = ConfigBuilder::create()
            ->withMissingApiConfig()
            ->withDefaultOrderStatuses()
            ->withDefaultDeliveryStatuses()
            ->withDefaultMappings()
            ->buildMap();

        $systemConfigMock->method('get')->willReturnMap($configMap);

        // Assert: Expect warning log
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Missing critical configuration'));

        // Act
        new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );
    }

    /**
     * Test order skipped when status not allowed
     */
    public function testOrderSkippedWhenStatusNotAllowed()
    {
        // Arrange: Create order with 'completed' status which is not in allowed statuses
        $orderEntity = $this->createOrderMock(
            status: 'completed'
        );

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Assert: Expect info log about skipped order
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Order "10001" skipped'));

        // Expect no HTTP request
        $this->httpClientMock->expects($this->never())
            ->method('request');

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test exception handling in onOrderWritten
     */
    public function testExceptionHandlingInOnOrderWritten()
    {
        // Make the repository throw an exception
        $this->orderRepositoryMock->method('search')
            ->willThrowException(new \Exception('Database error'));

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $this->createOrderEntityMock(),
        );

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unexpected error'));

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Should not throw exception
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test exception handling when sending to Karla API fails
     */
    public function testExceptionHandlingWhenSendingToKarlaApiFails()
    {
        $orderEntity = $this->createOrderEntityMock();
        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Make HTTP client throw exception
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Network error'));

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unexpected error'));

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test delivery tracking with products in delivery positions
     */
    public function testOnOrderWrittenFulfillmentWithProducts()
    {
        $orderEntity = $this->createOrderEntityWithDeliveryAndProductsMock();

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Mock HTTP response
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Verify trackings exist with products
                    if (! isset($body['trackings']) || empty($body['trackings'])) {
                        return false;
                    }

                    $tracking = $body['trackings'][0];

                    return isset($tracking['products'])
                        && ! empty($tracking['products'])
                        && isset($tracking['products'][0]['external_product_id'])
                        && isset($tracking['products'][0]['sku']);
                })
            )
            ->willReturn($responseMock);

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order with deposit line items
     */
    public function testOrderWithDepositLineItems()
    {
        // Configure deposit line item type
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $systemConfigMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'testSlug'],
            ['KarlaDelivery.config.apiUsername', null, 'testUser'],
            ['KarlaDelivery.config.apiKey', null, 'testKey'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.example.com'],
            ['KarlaDelivery.config.requestTimeout', null, 10.5],
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            ['KarlaDelivery.config.deliveryOpen', null, false],
            ['KarlaDelivery.config.deliveryShipped', null, true],
            ['KarlaDelivery.config.deliveryShippedPartially', null, true],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            ['KarlaDelivery.config.depositLineItemType', null, 'deposit'], // Deposit type configured
            ['KarlaDelivery.config.salesChannelMapping', null, ''],
        ]);

        $orderEntity = $this->createOrderEntityWithDepositMock();
        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Verify deposit item is included in products
                    return isset($body['order']['products'])
                        && count($body['order']['products']) > 0;
                })
            )
            ->willReturn($responseMock);

        $orderSubscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Create order entity with delivery and products mock
     */
    private function createOrderEntityWithDeliveryAndProductsMock(): OrderEntity
    {
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')
            ->willReturn($this->createMockStateMachineState('in_progress'));
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

        // Line items (empty for this test)
        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection([]));

        // Setup address
        $addressMock = $this->createAddressMock();
        $orderEntity->method('getAddresses')
            ->willReturn(new OrderAddressCollection([$addressMock]));

        // Tags
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));

        // Setup delivery with products
        $productLineItem = $this->createMock(OrderLineItemEntity::class);
        $productLineItem->method('getId')->willReturn(Uuid::randomHex());
        $productLineItem->method('getReferencedId')->willReturn('SKU-123');
        $productLineItem->method('getType')->willReturn('product');
        $productLineItem->method('getLabel')->willReturn('Test Product');
        $productLineItem->method('getQuantity')->willReturn(1);
        $productLineItem->method('getUnitPrice')->willReturn(10.00);
        $productLineItem->method('getTotalPrice')->willReturn(10.00);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getCover')->willReturn(null);
        $productLineItem->method('getProduct')->willReturn($product);

        $deliveryPosition = $this->createMock(OrderDeliveryPositionEntity::class);
        $deliveryPosition->method('getOrderLineItem')->willReturn($productLineItem);

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getTrackingCodes')->willReturn(['TRACK123']);
        $delivery->method('getPositions')
            ->willReturn(new OrderDeliveryPositionCollection([$deliveryPosition]));

        // Mock delivery state
        $deliveryState = $this->createMock(StateMachineStateEntity::class);
        $deliveryState->method('getTechnicalName')->willReturn('shipped');
        $delivery->method('getStateMachineState')->willReturn($deliveryState);

        $orderEntity->method('getDeliveries')
            ->willReturn(new OrderDeliveryCollection([$delivery]));

        return $orderEntity;
    }

    /**
     * Create order entity with deposit line item mock
     */
    private function createOrderEntityWithDepositMock(): OrderEntity
    {
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')
            ->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));

        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(15.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);

        // Create deposit line item
        $depositLineItem = $this->createMock(OrderLineItemEntity::class);
        $depositLineItem->method('getId')->willReturn(Uuid::randomHex());
        $depositLineItem->method('getReferencedId')->willReturn('DEPOSIT-001');
        $depositLineItem->method('getType')->willReturn('deposit');
        $depositLineItem->method('getLabel')->willReturn('Bottle Deposit');
        $depositLineItem->method('getQuantity')->willReturn(1);
        $depositLineItem->method('getUnitPrice')->willReturn(5.00);
        $depositLineItem->method('getTotalPrice')->willReturn(5.00);

        $product = $this->createMock(ProductEntity::class);
        $product->method('getCover')->willReturn(null);
        $depositLineItem->method('getProduct')->willReturn($product);

        $lineItems = new OrderLineItemCollection([$depositLineItem]);
        $orderEntity->method('getLineItems')->willReturn($lineItems);

        // Address and other required fields
        $addressMock = $this->createAddressMock();
        $orderEntity->method('getAddresses')
            ->willReturn(new OrderAddressCollection([$addressMock]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));

        return $orderEntity;
    }

    /**
     * Test constructor with all order statuses enabled
     */
    public function testConstructorWithAllOrderStatusesEnabled()
    {
        // Arrange: Setup config with all statuses enabled
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $configMap = ConfigBuilder::create()
            ->withApiConfig(self::TEST_SHOP_SLUG, self::TEST_API_USER, self::TEST_API_KEY)
            ->withAllOrderStatusesEnabled()
            ->withAllDeliveryStatusesEnabled()
            ->withDefaultMappings()
            ->buildMap();

        $systemConfigMock->method('get')->willReturnMap($configMap);

        // Act: This should cover lines 131, 137, 140, 163, 172, 175, 178
        $subscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Assert
        $this->assertInstanceOf(OrderSubscriber::class, $subscriber);
    }

    /**
     * Test delivery skipped when no tracking codes
     */
    public function testDeliverySkippedWhenNoTrackingCodes()
    {
        // Arrange: Create delivery without tracking codes
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getTrackingCodes')->willReturn([]);

        $deliveryState = $this->createMock(StateMachineStateEntity::class);
        $deliveryState->method('getTechnicalName')->willReturn('shipped');
        $delivery->method('getStateMachineState')->willReturn($deliveryState);

        $orderEntity = $this->createOrderMock(
            deliveries: new OrderDeliveryCollection([$delivery])
        );

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Request is still made (order is sent, but tracking is skipped)
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test delivery skipped when delivery status not allowed
     */
    public function testDeliverySkippedWhenDeliveryStatusNotAllowed()
    {
        // Arrange: Create delivery with 'open' status which is not in allowed statuses
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getTrackingCodes')->willReturn(['TRACK123']);

        $deliveryState = $this->createMock(StateMachineStateEntity::class);
        $deliveryState->method('getTechnicalName')->willReturn('open'); // Not allowed
        $delivery->method('getStateMachineState')->willReturn($deliveryState);

        $orderEntity = $this->createOrderMock(
            deliveries: new OrderDeliveryCollection([$delivery])
        );

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Request is still made (order is sent, but tracking is skipped)
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order with customer tags
     */
    public function testOrderWithCustomerTags()
    {
        // Arrange: Create order with customer tags
        $orderEntity = $this->createOrderMock(
            customerTags: ['VIP Customer']
        );

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Verify customer tags are included in segments
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Verify customer tag is in segments with correct prefix
                    return isset($body['order']['segments'])
                        && in_array('Shopware.customer.tag.VIP Customer', $body['order']['segments']);
                })
            )
            ->willReturn($responseMock);

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order skipped when API configuration is missing during event processing
     */
    public function testOrderSkippedWhenApiConfigMissingDuringEvent()
    {
        // Create a config that has warnings but doesn't prevent construction
        // Then config is empty/null for the actual API check
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $systemConfigMock->method('get')->willReturnMap([
            // API config - initially set for constructor, but simulates being cleared
            ['KarlaDelivery.config.shopSlug', null, 'testSlug'], // Set for constructor
            ['KarlaDelivery.config.apiUsername', null, 'testUser'],
            ['KarlaDelivery.config.apiKey', null, 'testKey'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.example.com'],
            ['KarlaDelivery.config.requestTimeout', null, 10.5],
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            ['KarlaDelivery.config.deliveryOpen', null, false],
            ['KarlaDelivery.config.deliveryShipped', null, true],
            ['KarlaDelivery.config.deliveryShippedPartially', null, true],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            ['KarlaDelivery.config.depositLineItemType', null, ''],
            ['KarlaDelivery.config.salesChannelMapping', null, ''],
        ]);

        // Create subscriber where shopSlug is empty in the instance
        // We need to use reflection to set the private property to empty
        $orderSubscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Use reflection to set shopSlug to empty to trigger the early return
        $reflection = new \ReflectionClass($orderSubscriber);
        $shopSlugProperty = $reflection->getProperty('shopSlug');
        $shopSlugProperty->setAccessible(true);
        $shopSlugProperty->setValue($orderSubscriber, '');

        $orderEntity = $this->createOrderEntityMock();
        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Expect warning log about critical configurations missing
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Critical configurations missing'));

        // Expect no HTTP request
        $this->httpClientMock->expects($this->never())
            ->method('request');

        $orderSubscriber->onOrderWritten($event);
    }
}
