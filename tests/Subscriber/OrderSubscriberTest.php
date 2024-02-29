<?php

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Subscriber\OrderSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\OrderDefinition;
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

    public function testOnOrderWrittenWithSendOrderPlacementsEnabled()
    {
        // Mock event
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();
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
        $event = new EntityWrittenEvent(
            OrderDefinition::ENTITY_NAME,
            [$entityWriteResult],
            $context
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
        $lineItemMock = $this->createMock(OrderLineItemEntity::class);
        $lineItemMock->method('getId')->willReturn(Uuid::randomHex());
        $lineItemsCollection = new OrderLineItemCollection([$lineItemMock]);
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
        $entitySearchResult = new EntitySearchResult(
            OrderDefinition::ENTITY_NAME,
            1, // total results
            $orderCollection,
            null, // aggregations
            $criteria,
            $context
        );
        $this->orderRepositoryMock->expects($this->any())
            ->method('search')
            ->willReturn($entitySearchResult);

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
