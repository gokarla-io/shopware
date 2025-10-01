<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Support;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\Tag\TagCollection;
use Shopware\Core\System\Tag\TagEntity;

/**
 * Helper trait for creating Shopware Order entity mocks in tests
 *
 * Usage in your test class:
 *   use OrderMockBuilderTrait;
 *
 * Then in your tests:
 *   $order = $this->createOrderMock(
 *       orderNumber: '10001',
 *       status: 'in_progress',
 *       tags: ['VIP', 'Priority'],
 *       customerTags: ['Premium']
 *   );
 */
trait OrderMockBuilderTrait
{
    /**
     * Create an order mock with common defaults and optional customization
     *
     * @param string $orderNumber Order number (default: '10001')
     * @param float $totalAmount Total order amount (default: 100.00)
     * @param string $status Order status (default: 'in_progress')
     * @param array<string> $tags Order tag names
     * @param array<string> $customerTags Customer tag names
     * @param OrderDeliveryCollection|null $deliveries Delivery collection
     * @param OrderLineItemCollection|null $lineItems Line item collection
     * @param bool $includeAddress Whether to include address (default: true)
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderEntity
     */
    protected function createOrderMock(
        string $orderNumber = '10001',
        float $totalAmount = 100.00,
        string $status = 'in_progress',
        array $tags = [],
        array $customerTags = [],
        ?OrderDeliveryCollection $deliveries = null,
        ?OrderLineItemCollection $lineItems = null,
        bool $includeAddress = true
    ): OrderEntity {
        $orderEntity = $this->createMock(OrderEntity::class);

        // Basic properties
        $orderEntity->method('getId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getOrderNumber')->willReturn($orderNumber);
        $orderEntity->method('getAmountTotal')->willReturn($totalAmount);
        $orderEntity->method('getStateId')->willReturn(Uuid::randomHex());
        $orderEntity->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2020-01-01 10:00:00'));
        $orderEntity->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        // Status
        $stateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMock->method('getTechnicalName')->willReturn($status);
        $orderEntity->method('getStateMachineState')->willReturn($stateMock);

        // Price
        $priceMock = $this->createMock(CartPrice::class);
        $priceMock->method('getTotalPrice')->willReturn($totalAmount);
        $orderEntity->method('getPrice')->willReturn($priceMock);
        $orderEntity->method('getShippingTotal')->willReturn(0.0);

        // Tags
        $tagCollection = new TagCollection();
        foreach ($tags as $tagName) {
            $tag = $this->createMock(TagEntity::class);
            $tag->method('getName')->willReturn($tagName);
            $tagCollection->add($tag);
        }
        $orderEntity->method('getTags')->willReturn($tagCollection);

        // Customer with tags
        if (! empty($customerTags)) {
            $customerTagCollection = new TagCollection();
            foreach ($customerTags as $tagName) {
                $tag = $this->createMock(TagEntity::class);
                $tag->method('getName')->willReturn($tagName);
                $customerTagCollection->add($tag);
            }

            $customer = $this->createMock(CustomerEntity::class);
            $customer->method('getTags')->willReturn($customerTagCollection);

            $orderCustomer = $this->createMock(OrderCustomerEntity::class);
            $orderCustomer->method('getCustomer')->willReturn($customer);

            $orderEntity->method('getOrderCustomer')->willReturn($orderCustomer);
        } else {
            $orderEntity->method('getOrderCustomer')->willReturn(null);
        }

        // Currency
        $orderEntity->method('getCurrency')->willReturn(null);

        // Line items
        $orderEntity->method('getLineItems')->willReturn($lineItems ?? new OrderLineItemCollection([]));

        // Address
        if ($includeAddress) {
            $addressEntity = $this->createOrderAddressMock();
            $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([$addressEntity]));
        } else {
            $orderEntity->method('getAddresses')->willReturn(new OrderAddressCollection([]));
        }

        // Deliveries
        $orderEntity->method('getDeliveries')->willReturn($deliveries ?? new OrderDeliveryCollection([]));

        return $orderEntity;
    }

    /**
     * Create an order address mock
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderAddressEntity
     */
    protected function createOrderAddressMock(): OrderAddressEntity
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
        $addressEntity->method('getStreet')->willReturn('Example Street');
        $addressEntity->method('getZipcode')->willReturn('12345');
        $addressEntity->method('getFirstName')->willReturn('John');
        $addressEntity->method('getLastName')->willReturn('Doe');

        return $addressEntity;
    }
}
