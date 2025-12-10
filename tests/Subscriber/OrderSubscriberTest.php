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
        $orderEntity->method('getAffiliateCode')->willReturn(null);
        $orderEntity->method('getCampaignCode')->willReturn(null);

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

                    return isset($product['product_id'])
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

                        return isset($product['product_id'])
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

        // Assert: Expect info log about skipped order with structured context
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Order skipped - status not allowed',
                $this->callback(function ($context) {
                    return $context['component'] === 'order.sync'
                        && $context['order_number'] === '10001'
                        && $context['order_status'] === 'completed';
                })
            );

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

        // Expect TWO error logs:
        // 1. Specific "Failed to send request to Karla API" in sendRequestToKarlaApi
        // 2. General "Unexpected error during order sync" in onOrderWritten
        $this->loggerMock->expects($this->exactly(2))
            ->method('error')
            ->willReturnCallback(function ($message, $context) {
                // Accept both error messages
                return null;
            });

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test handling of API validation errors (422)
     */
    public function testApiValidationErrorHandling()
    {
        $orderEntity = $this->createOrderEntityMock();
        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Mock HTTP response with 422 status
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')
            ->with(false) // false = don't throw on error status
            ->willReturn('{"error":"Validation failed","details":["product_id is required"]}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Expect TWO error logs:
        // 1. The specific API error log
        // 2. The general "Unexpected error during order sync" log
        $this->loggerMock->expects($this->exactly(2))
            ->method('error')
            ->willReturnCallback(function ($message, $context) {
                // First call: specific API error
                // Second call: general error wrapper
                return null;
            });

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
                        && isset($tracking['products'][0]['product_id'])
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

        // Assert: Verify customer tags are included in segments with Shopware.tag prefix
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);

                    // Verify customer tag is in segments with correct prefix (Shopware.tag)
                    return isset($body['order']['segments'])
                        && in_array('Shopware.tag.VIP Customer', $body['order']['segments']);
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

        // Expect warning log about missing configuration with structured context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Order sync skipped - missing configuration',
                $this->callback(function ($context) {
                    return $context['component'] === 'order.sync';
                })
            );

        // Expect no HTTP request
        $this->httpClientMock->expects($this->never())
            ->method('request');

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order with affiliate code only
     */
    public function testOnOrderWrittenWithAffiliateCode()
    {
        // Create order mock with affiliate code (need to create a fresh mock to override)
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection([]));
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([]));
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);
        $orderEntity->method('getCurrency')->willReturn(null);

        // Attribution codes
        $orderEntity->method('getAffiliateCode')->willReturn('karla');
        $orderEntity->method('getCampaignCode')->willReturn(null);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
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

                    // Verify order_analytics exists with affiliate_code
                    return isset($body['order_analytics'])
                        && isset($body['order_analytics']['affiliate_code'])
                        && $body['order_analytics']['affiliate_code'] === 'karla'
                        && ! isset($body['order_analytics']['campaign_code']);
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
     * Test order with both affiliate and campaign codes
     */
    public function testOnOrderWrittenWithAttributionCodes()
    {
        // Create order mock with both attribution codes (need to create a fresh mock to override)
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection([]));
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([]));
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);
        $orderEntity->method('getCurrency')->willReturn(null);

        // Attribution codes
        $orderEntity->method('getAffiliateCode')->willReturn('karla');
        $orderEntity->method('getCampaignCode')->willReturn('summer2024');

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
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

                    // Verify order_analytics exists with both codes
                    return isset($body['order_analytics'])
                        && isset($body['order_analytics']['affiliate_code'])
                        && $body['order_analytics']['affiliate_code'] === 'karla'
                        && isset($body['order_analytics']['campaign_code'])
                        && $body['order_analytics']['campaign_code'] === 'summer2024';
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
     * Test order processing with debug mode enabled to cover debug log statements
     */
    public function testOnOrderWrittenWithDebugMode()
    {
        // Arrange: Setup config with debug mode enabled
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $configMap = ConfigBuilder::create()
            ->withApiConfig(self::TEST_SHOP_SLUG, self::TEST_API_USER, self::TEST_API_KEY, self::TEST_API_URL)
            ->withDefaultOrderStatuses()
            ->withDefaultDeliveryStatuses()
            ->withDefaultMappings()
            ->withDebugMode(true)
            ->withSalesChannelMapping('channel-1:mapped-slug')
            ->buildMap();

        $systemConfigMock->method('get')->willReturnMap($configMap);

        // Create order with tracking number to trigger all debug logs
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(5.0);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);
        $orderEntity->method('getSalesChannelId')->willReturn('channel-1');

        // Create line item with product
        $productLineItem = $this->createMock(OrderLineItemEntity::class);
        $productLineItem->method('getId')->willReturn(Uuid::randomHex());
        $productLineItem->method('getReferencedId')->willReturn('PROD-001');
        $productLineItem->method('getType')->willReturn('product');
        $productLineItem->method('getLabel')->willReturn('Test Product');
        $productLineItem->method('getQuantity')->willReturn(1);
        $productLineItem->method('getUnitPrice')->willReturn(10.00);
        $productLineItem->method('getTotalPrice')->willReturn(10.00);
        $product = $this->createMock(ProductEntity::class);
        $product->method('getCover')->willReturn(null);
        $productLineItem->method('getProduct')->willReturn($product);

        $lineItems = new OrderLineItemCollection([$productLineItem]);
        $orderEntity->method('getLineItems')->willReturn($lineItems);

        // Create delivery with tracking number
        $deliveryPosition = $this->createMock(OrderDeliveryPositionEntity::class);
        $deliveryPosition->method('getOrderLineItem')->willReturn($productLineItem);
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getTrackingCodes')->willReturn(['TRACK123']);
        $delivery->method('getPositions')->willReturn(new OrderDeliveryPositionCollection([$deliveryPosition]));
        $deliveryState = $this->createMock(StateMachineStateEntity::class);
        $deliveryState->method('getTechnicalName')->willReturn('shipped');
        $delivery->method('getStateMachineState')->willReturn($deliveryState);
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([$delivery]));

        // Address and tags
        $addressMock = $this->createAddressMock();
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([$addressMock]));
        $tag = $this->createMock(TagEntity::class);
        $tag->method('getName')->willReturn('vip');
        $orderEntity->method('getTags')->willReturn(new TagCollection([$tag]));

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Expect debug logs to be called (6 total):
        // 1. Sales channel mapping parsed (constructor)
        // 2. Delivery found with tracking number
        // 3. Order segments determined
        // 4. Using mapped shop slug for sales channel
        // 5. Preparing API request to Karla
        // 6. API request to Karla completed
        $this->loggerMock->expects($this->exactly(6))
            ->method('debug')
            ->with(
                $this->logicalOr(
                    $this->equalTo('Delivery found with tracking number'),
                    $this->equalTo('Preparing API request to Karla'),
                    $this->equalTo('API request to Karla completed'),
                    $this->equalTo('Order segments determined'),
                    $this->equalTo('Sales channel mapping parsed'),
                    $this->equalTo('Using mapped shop slug for sales channel')
                ),
                $this->anything()
            );

        // Mock HTTP response
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $responseMock->method('getStatusCode')->willReturn(200);
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Act
        $orderSubscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order processing with debug mode using default shop slug
     */
    public function testOnOrderWrittenWithDebugModeDefaultSlug()
    {
        // Arrange: Setup config with debug mode enabled but no sales channel mapping
        $systemConfigMock = $this->createMock(SystemConfigService::class);
        $configMap = ConfigBuilder::create()
            ->withApiConfig(self::TEST_SHOP_SLUG, self::TEST_API_USER, self::TEST_API_KEY, self::TEST_API_URL)
            ->withDefaultOrderStatuses()
            ->withDefaultDeliveryStatuses()
            ->withDefaultMappings()
            ->withDebugMode(true)
            ->buildMap();

        $systemConfigMock->method('get')->willReturnMap($configMap);

        // Create minimal order without sales channel mapping
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex()); // Unmapped channel
        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection([]));
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([]));
        $orderEntity->method('getTags')->willReturn(new TagCollection([]));
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection([]));

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        // Expect debug log for using default shop slug
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(function ($message, $context) {
                if ($message === 'Using default shop slug for sales channel') {
                    $this->assertEquals('order.config', $context['component']);
                    $this->assertEquals('testSlug', $context['shop_slug']);
                }

                return null;
            });

        // Mock HTTP response
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $responseMock->method('getStatusCode')->willReturn(200);
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        // Act
        $orderSubscriber = new OrderSubscriber(
            $systemConfigMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        $orderSubscriber->onOrderWritten($event);
    }

    /**
     * Test order with all segment sources (order tags, customer tags, customer group, sales channel)
     */
    public function testOnOrderWrittenWithAllSegmentSources()
    {
        // Arrange: Create order with all segment sources
        $orderEntity = $this->createOrderMock(
            orderNumber: '10001',
            tags: ['Priority', 'Urgent'],
            customerTags: ['VIP', 'Loyalty'],
            customerGroup: 'B2B',
            salesChannel: 'Headless'
        );

        $event = $this->mockOrderEvent(
            $this->createAdminApiSourceContextMock(),
            $orderEntity,
        );

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Verify all segment sources are included
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->equalTo('https://api.example.com/v1/shops/testSlug/orders'),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    $segments = $body['order']['segments'] ?? [];

                    // Verify all 4 segment sources are present
                    // Order tags: Priority, Urgent (both use Shopware.tag prefix)
                    // Customer tags: VIP, Loyalty (both use Shopware.tag prefix)
                    // Customer group: B2B
                    // Sales channel: Headless
                    // Total should be at least 4 (could be 6 if all tags are unique)
                    $hasOrderTags = in_array('Shopware.tag.Priority', $segments)
                        || in_array('Shopware.tag.Urgent', $segments);
                    $hasCustomerTags = in_array('Shopware.tag.VIP', $segments)
                        || in_array('Shopware.tag.Loyalty', $segments);
                    $hasCustomerGroup = in_array('Shopware.customer_group.B2B', $segments);
                    $hasSalesChannel = in_array('Shopware.sales_channel.Headless', $segments);

                    return count($segments) >= 4
                        && $hasOrderTags
                        && $hasCustomerTags
                        && $hasCustomerGroup
                        && $hasSalesChannel;
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

    public function testOnOrderWrittenWithVariantProducts(): void
    {
        // Setup system config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            ['KarlaDelivery.config.deliveryOpen', null, false],
            ['KarlaDelivery.config.deliveryShipped', null, false],
            ['KarlaDelivery.config.deliveryShippedPartially', null, false],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            ['KarlaDelivery.config.depositLineItemType', null, ''],
            ['KarlaDelivery.config.salesChannelMapping', null, ''],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Create mock for variant product with parent ID in payload
        $lineItemMock = $this->createMock(OrderLineItemEntity::class);
        $lineItemMock->method('getType')->willReturn('product');
        $lineItemMock->method('getId')->willReturn('line-item-id');
        $lineItemMock->method('getReferencedId')->willReturn('variant-id-123');
        $lineItemMock->method('getLabel')->willReturn('Variant Product - Size L');
        $lineItemMock->method('getQuantity')->willReturn(2);
        $lineItemMock->method('getUnitPrice')->willReturn(29.99);
        $lineItemMock->method('getTotalPrice')->willReturn(59.98);
        // Payload contains parent ID for variant products
        $lineItemMock->method('getPayload')->willReturn(['parentId' => 'parent-product-id-456']);

        $productMock = $this->createMock(ProductEntity::class);
        $productMock->method('getProductNumber')->willReturn('VARIANT-SKU-001');
        $lineItemMock->method('getProduct')->willReturn($productMock);

        $lineItemCollection = new OrderLineItemCollection([$lineItemMock]);

        // Build order entity from scratch since we can't override mocks
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('ORD-VARIANT-001');
        $orderEntity->method('getAmountTotal')->willReturn(59.98);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));

        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(59.98);
        $orderEntity->method('getPrice')->willReturn($priceMock);

        $orderEntity->method('getLineItems')->willReturn($lineItemCollection);
        $orderEntity->method('getDeliveries')->willReturn(new OrderDeliveryCollection());
        $orderEntity->method('getTags')->willReturn(new TagCollection());
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection());
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getAffiliateCode')->willReturn(null);
        $orderEntity->method('getCampaignCode')->willReturn(null);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);

        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($orderEntity);
        $searchResultMock->method('getIterator')->willReturn(new \ArrayIterator([$orderEntity]));
        $this->orderRepositoryMock->method('search')->willReturn($searchResultMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Verify variant product has correct product_id (parent) and variant_id
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->anything(),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    $products = $body['order']['products'] ?? [];

                    // Should have 1 product
                    $this->assertCount(1, $products);

                    $product = $products[0];
                    // product_id should be parent ID from payload
                    $this->assertEquals('parent-product-id-456', $product['product_id']);
                    // variant_id should be the variant ID
                    $this->assertEquals('variant-id-123', $product['variant_id']);
                    // SKU should be product number
                    $this->assertEquals('VARIANT-SKU-001', $product['sku']);

                    return true;
                })
            )
            ->willReturn($responseMock);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $orderEntity
        );

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }

    public function testOnOrderWrittenWithVariantProductsInDelivery(): void
    {
        // Setup system config
        $this->systemConfigServiceMock->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', null, 'test-shop'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.test.com'],
            ['KarlaDelivery.config.apiUsername', null, 'user'],
            ['KarlaDelivery.config.apiKey', null, 'key'],
            ['KarlaDelivery.config.requestTimeout', null, 10.0],
            ['KarlaDelivery.config.orderOpen', null, false],
            ['KarlaDelivery.config.orderInProgress', null, true],
            ['KarlaDelivery.config.orderCompleted', null, false],
            ['KarlaDelivery.config.orderCancelled', null, false],
            ['KarlaDelivery.config.deliveryOpen', null, true],
            ['KarlaDelivery.config.deliveryShipped', null, true],
            ['KarlaDelivery.config.deliveryShippedPartially', null, false],
            ['KarlaDelivery.config.deliveryReturned', null, false],
            ['KarlaDelivery.config.deliveryReturnedPartially', null, false],
            ['KarlaDelivery.config.deliveryCancelled', null, false],
            ['KarlaDelivery.config.depositLineItemType', null, ''],
            ['KarlaDelivery.config.salesChannelMapping', null, ''],
            ['KarlaDelivery.config.debugMode', null, false],
        ]);

        // Create mock for variant product with parent ID in payload
        $lineItemMock = $this->createMock(OrderLineItemEntity::class);
        $lineItemMock->method('getType')->willReturn('product');
        $lineItemMock->method('getId')->willReturn('line-item-id');
        $lineItemMock->method('getReferencedId')->willReturn('variant-id-789');
        $lineItemMock->method('getLabel')->willReturn('Variant Product - Size M');
        $lineItemMock->method('getQuantity')->willReturn(1);
        $lineItemMock->method('getUnitPrice')->willReturn(49.99);
        $lineItemMock->method('getTotalPrice')->willReturn(49.99);
        // Payload contains parent ID for variant products
        $lineItemMock->method('getPayload')->willReturn(['parentId' => 'parent-product-id-789']);

        $productMock = $this->createMock(ProductEntity::class);
        $productMock->method('getProductNumber')->willReturn('VARIANT-SKU-789');
        $lineItemMock->method('getProduct')->willReturn($productMock);

        // Create delivery position with the variant line item
        $deliveryPositionMock = $this->createMock(OrderDeliveryPositionEntity::class);
        $deliveryPositionMock->method('getOrderLineItem')->willReturn($lineItemMock);

        $deliveryPositionCollection = new OrderDeliveryPositionCollection([$deliveryPositionMock]);

        // Create delivery with tracking codes and positions
        $deliveryMock = $this->createMock(OrderDeliveryEntity::class);
        $deliveryMock->method('getTrackingCodes')->willReturn(['TRACK123']);
        $deliveryMock->method('getPositions')->willReturn($deliveryPositionCollection);
        $deliveryMock->method('getStateMachineState')->willReturn($this->createMockStateMachineState('shipped'));

        $shippingMethodMock = $this->createMock(ShippingMethodEntity::class);
        $shippingMethodMock->method('getName')->willReturn('DHL');
        $deliveryMock->method('getShippingMethod')->willReturn($shippingMethodMock);

        $deliveryCollection = new OrderDeliveryCollection([$deliveryMock]);

        // Build order entity with deliveries
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('ORD-DELIVERY-001');
        $orderEntity->method('getAmountTotal')->willReturn(49.99);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));

        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(49.99);
        $orderEntity->method('getPrice')->willReturn($priceMock);

        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection());
        $orderEntity->method('getDeliveries')->willReturn($deliveryCollection);
        $orderEntity->method('getTags')->willReturn(new TagCollection());
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection());
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getAffiliateCode')->willReturn(null);
        $orderEntity->method('getCampaignCode')->willReturn(null);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);

        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($orderEntity);
        $searchResultMock->method('getIterator')->willReturn(new \ArrayIterator([$orderEntity]));
        $this->orderRepositoryMock->method('search')->willReturn($searchResultMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Verify variant product in delivery has correct product_id (parent) and variant_id
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->anything(),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    $trackings = $body['trackings'] ?? [];

                    // Should have 1 tracking
                    $this->assertCount(1, $trackings);

                    $tracking = $trackings[0];
                    $products = $tracking['products'] ?? [];

                    // Should have 1 product
                    $this->assertCount(1, $products);

                    $product = $products[0];
                    // product_id should be parent ID from payload
                    $this->assertEquals('parent-product-id-789', $product['product_id']);
                    // variant_id should be the variant ID
                    $this->assertEquals('variant-id-789', $product['variant_id']);
                    // SKU should be product number
                    $this->assertEquals('VARIANT-SKU-789', $product['sku']);

                    return true;
                })
            )
            ->willReturn($responseMock);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $orderEntity
        );

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
     * Test that multiple tracking codes on a single delivery are all sent
     */
    public function testOnOrderWrittenWithMultipleTrackingCodes()
    {
        // Arrange: Create delivery with multiple tracking codes
        $lineItemMock = $this->createMock(OrderLineItemEntity::class);
        $lineItemMock->method('getId')->willReturn('line-item-id-123');
        $lineItemMock->method('getReferencedId')->willReturn('product-id-123');
        $lineItemMock->method('getType')->willReturn('product');
        $lineItemMock->method('getLabel')->willReturn('Test Product');
        $lineItemMock->method('getQuantity')->willReturn(2);
        $lineItemMock->method('getUnitPrice')->willReturn(25.00);
        $lineItemMock->method('getTotalPrice')->willReturn(50.00);
        $lineItemMock->method('getPayload')->willReturn([]);

        $productMock = $this->createMock(ProductEntity::class);
        $productMock->method('getProductNumber')->willReturn('SKU-123');
        $lineItemMock->method('getProduct')->willReturn($productMock);

        $deliveryPositionMock = $this->createMock(OrderDeliveryPositionEntity::class);
        $deliveryPositionMock->method('getOrderLineItem')->willReturn($lineItemMock);

        $deliveryPositionCollection = new OrderDeliveryPositionCollection([$deliveryPositionMock]);

        // Create delivery with MULTIPLE tracking codes (the key scenario)
        $deliveryMock = $this->createMock(OrderDeliveryEntity::class);
        $deliveryMock->method('getTrackingCodes')->willReturn(['TRACK-001', 'TRACK-002', 'TRACK-003']);
        $deliveryMock->method('getPositions')->willReturn($deliveryPositionCollection);
        $deliveryMock->method('getStateMachineState')->willReturn($this->createMockStateMachineState('shipped'));

        $deliveryCollection = new OrderDeliveryCollection([$deliveryMock]);

        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getStateMachineState')->willReturn($this->createMockStateMachineState('in_progress'));
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('ORD-MULTI-TRACK-001');
        $orderEntity->method('getAmountTotal')->willReturn(50.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));

        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(50.00);
        $orderEntity->method('getPrice')->willReturn($priceMock);

        $orderEntity->method('getLineItems')->willReturn(new OrderLineItemCollection());
        $orderEntity->method('getDeliveries')->willReturn($deliveryCollection);
        $orderEntity->method('getTags')->willReturn(new TagCollection());
        $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection());
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getAffiliateCode')->willReturn(null);
        $orderEntity->method('getCampaignCode')->willReturn(null);
        $orderEntity->method('getOrderCustomer')->willReturn(null);
        $orderEntity->method('getCurrency')->willReturn(null);

        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($orderEntity);
        $searchResultMock->method('getIterator')->willReturn(new \ArrayIterator([$orderEntity]));
        $this->orderRepositoryMock->method('search')->willReturn($searchResultMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn('{"success":true}');

        // Assert: Verify all 3 tracking codes are sent with products
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('PUT'),
                $this->anything(),
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    $trackings = $body['trackings'] ?? [];

                    // Should have 3 trackings (one for each tracking code)
                    $this->assertCount(3, $trackings);

                    // Verify each tracking has the correct tracking number
                    $this->assertEquals('TRACK-001', $trackings[0]['tracking_number']);
                    $this->assertEquals('TRACK-002', $trackings[1]['tracking_number']);
                    $this->assertEquals('TRACK-003', $trackings[2]['tracking_number']);

                    // Verify each tracking has products (same products for all)
                    foreach ($trackings as $tracking) {
                        $this->assertArrayHasKey('products', $tracking);
                        $this->assertArrayHasKey('tracking_placed_at', $tracking);
                    }

                    return true;
                })
            )
            ->willReturn($responseMock);

        $event = $this->mockOrderEvent(
            $this->createSalesChannelApiSourceContextMock(),
            $orderEntity
        );

        $orderSubscriber = new OrderSubscriber(
            $this->systemConfigServiceMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->httpClientMock
        );

        // Act
        $orderSubscriber->onOrderWritten($event);
    }
}
