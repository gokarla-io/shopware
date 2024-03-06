<?php

namespace Karla\Delivery\Tests\Subscriber;

use JsonSerializable;
use Karla\Delivery\Subscriber\OrderSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Api\Context\ContextSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;

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
            ['KarlaDelivery.config.merchantSlug', null, 'testSlug'],
            ['KarlaDelivery.config.apiKey', null, 'testKey'],
            ['KarlaDelivery.config.apiUrl', null, 'https://api.example.com'],
            ['KarlaDelivery.config.sendOrderPlacements', null, true],
        ]);
    }

    private function mockFullOrderEvent(): EntityWrittenEvent {
        // Mock event
        $orderId = Uuid::randomHex();
        $orderData = [
            'id' => $orderId,
            'orderNumber' => '10001',
        ];
        $entityWriteResult = new EntityWriteResult(
            $orderId,
            $orderData,
            OrderDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT,
            null,
            null
        );
        // Mock order repository
        $criteria = new Criteria([$orderId]);
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn(10.00);
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn('10001');
        $orderEntity->method('getAmountTotal')->willReturn(100.00);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')
            ->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $productLineItemMock = $this->createMock(OrderLineItemEntity::class);
        $productLineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $productLineItemMock->method('getType')->willReturn('promotion');
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
        $promotionLineItemMock->method('getPayload')->willReturn(['discountType' => 'percentage', 'code' => 'discountCode']);
        $promotionMock = $this->createMock(PromotionEntity::class);
        $promotionMock->method('getCode')->willReturn("discount");
        $promotionLineItemMock->method('getPromotion')->willReturn($promotionMock);
        $lineItemsCollection = new OrderLineItemCollection([$productLineItemMock, $promotionLineItemMock]);
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
        $orderCollection = new EntityCollection([$orderEntity]);

        $context = $this->createMock(Context::class);
        $context->method('getSource')->willReturn($this->createMock(SalesChannelApiSource::class));
        $entitySearchResult = new EntitySearchResult(
            OrderDefinition::ENTITY_NAME,
            1, // total results
            $orderCollection,
            null, // aggregations
            $criteria,
            $context
        );

        $event = new EntityWrittenEvent(
            OrderDefinition::ENTITY_NAME,
            [$entityWriteResult],
            $context
        );
        $this->orderRepositoryMock->expects($this->any())
            ->method('search')
            ->willReturn($entitySearchResult);
        return $event;
    }


    private function mockPartialOrderEvent(): EntityWrittenEvent {
        // Mock event
        $orderId = Uuid::randomHex();
        $orderData = [
            'id' => $orderId,
            'orderNumber' => '10001',
        ];
        $entityWriteResult = new EntityWriteResult(
            $orderId,
            $orderData,
            OrderDefinition::ENTITY_NAME,
            EntityWriteResult::OPERATION_INSERT,
            null,
            null
        );
        // Mock order repository
        $criteria = new Criteria([$orderId]);
        $orderEntity = $this->createMock(OrderEntity::class);
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
        $promotionLineItemMock->method('getPayload')->willReturn(['discountType' => 'percentage', 'code' => 'discountCode']);
        $promotionLineItemMock->method('getPromotion')->willReturn(null);
        $lineItemsCollection = new OrderLineItemCollection([$productLineItemMock, $promotionLineItemMock]);
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
        $orderCollection = new EntityCollection([$orderEntity]);

        $context = $this->createMock(Context::class);
        $context->method('getSource')->willReturn($this->createMock(SalesChannelApiSource::class));
        $entitySearchResult = new EntitySearchResult(
            OrderDefinition::ENTITY_NAME,
            1, // total results
            $orderCollection,
            null, // aggregations
            $criteria,
            $context
        );

        $event = new EntityWrittenEvent(
            OrderDefinition::ENTITY_NAME,
            [$entityWriteResult],
            $context
        );
        $this->orderRepositoryMock->expects($this->any())
            ->method('search')
            ->willReturn($entitySearchResult);
        return $event;
    }

    public function testOnOrderWrittenFull()
    {
        $event = $this->mockFullOrderEvent();
        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $this->httpClientMock->expects(
            $this->once())->method('request')->with(
                $this->equalTo('POST'),
                $this->equalTo('https://api.example.com/v1/orders'),
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

    public function testOnOrderWrittenPartial()
    {
        $event = $this->mockPartialOrderEvent();
        // Mock HTTP response and its expectation
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getContent')->willReturn('{"success":true}');
        $this->httpClientMock->expects(
            $this->once())->method('request')->with(
                $this->equalTo('POST'),
                $this->equalTo('https://api.example.com/v1/orders'),
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
}
