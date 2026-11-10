<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Service\MigrationProtectionService;
use Karla\Delivery\Service\TrackpageUrlService;
use Karla\Delivery\Subscriber\OrderSubscriber;
use Karla\Delivery\Tests\Support\ConfigBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bill-of-materials ("Stückliste") line items, ENG-3386.
 *
 * The bundle plugin puts the price on a parent line item of a custom type and adds
 * a €0.00 product child for stock. The delivery position points at the parent.
 */
class OrderSubscriberBundleLineItemsTest extends TestCase
{
    private OrderSubscriber $subscriber;

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap(
            ConfigBuilder::create()
                ->withApiConfig('test-shop', 'user', 'key', 'https://api.gokarla.io')
                ->withDefaultOrderStatuses()
                ->withDefaultDeliveryStatuses()
                ->withDefaultMappings()
                ->buildMap()
        );

        $this->subscriber = new OrderSubscriber(
            $config,
            $logger,
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(TrackpageUrlService::class),
            new MigrationProtectionService($config, $logger),
        );
    }

    private function lineItem(
        string $type,
        string $label,
        float $unitPrice,
        ?string $parentId = null,
        ?ProductEntity $product = null,
        int $quantity = 1
    ): OrderLineItemEntity {
        $item = new OrderLineItemEntity();
        $item->setId(Uuid::randomHex());
        $item->setType($type);
        $item->setLabel($label);
        $item->setQuantity($quantity);
        $item->setUnitPrice($unitPrice);
        $item->setTotalPrice($unitPrice * $quantity);
        if ($parentId !== null) {
            $item->setParentId($parentId);
        }
        $item->setReferencedId($product?->getId() ?? Uuid::randomHex());
        $item->setPayload([]);
        if ($product !== null) {
            $item->setProduct($product);
        }

        return $item;
    }

    private function product(string $productNumber): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId(Uuid::randomHex());
        $product->setProductNumber($productNumber);

        return $product;
    }

    /**
     * @return array{OrderLineItemCollection, OrderLineItemEntity, OrderLineItemEntity, OrderLineItemEntity}
     */
    private function b800131LineItems(): array
    {
        $salami = $this->lineItem('product', 'Levoni Salami Milano, 250 g', 9.99, null, $this->product('LV_733'));
        $bundle = $this->lineItem('bill-of-materials', 'Latteria Sorrentina Fior di Latte Mini-Pack, 150 g', 6.89);
        $stock = $this->lineItem(
            'product',
            'Stückliste SO_070803_1X: [BESTAND] Latteria Sorrentina Fior di Latte Mini-Pack, 150 g',
            0.0,
            $bundle->getId(),
            $this->product('SO_070803_BESTAND'),
        );

        return [new OrderLineItemCollection([$salami, $bundle, $stock]), $salami, $bundle, $stock];
    }

    /**
     * @return mixed
     */
    private function call(string $method, mixed ...$args)
    {
        $reflection = new \ReflectionMethod($this->subscriber, $method);

        return $reflection->invoke($this->subscriber, ...$args);
    }

    private function position(OrderLineItemEntity $lineItem): OrderDeliveryPositionEntity
    {
        $position = new OrderDeliveryPositionEntity();
        $position->setId(Uuid::randomHex());
        $position->setOrderLineItem($lineItem);

        return $position;
    }

    public function testBundleParentIsSentWithItsPriceAndChildIsNotDuplicated(): void
    {
        [$lineItems, , $bundle, $stock] = $this->b800131LineItems();

        $result = $this->call('readLineItems', $lineItems);

        $this->assertCount(2, $result['products']);
        $this->assertSame(['LV_733', 'SO_070803_BESTAND'], array_column($result['products'], 'sku'));
        $bundleProduct = $result['products'][1];
        $this->assertSame($bundle->getLabel(), $bundleProduct['title']);
        $this->assertSame(6.89, $bundleProduct['price']);
        // No own product on the parent: identity comes from the single product child.
        $this->assertSame($stock->getReferencedId(), $bundleProduct['variant_id']);
        $this->assertEqualsWithDelta(16.88, $result['subTotalPrice'], 0.0001);
    }

    public function testDeliveryPositionOnBundleParentMatchesOrderProducts(): void
    {
        [$lineItems, $salami, $bundle] = $this->b800131LineItems();
        $positions = new OrderDeliveryPositionCollection([$this->position($salami), $this->position($bundle)]);

        $order = $this->call('readLineItems', $lineItems);
        $shipment = $this->call('readDeliveryPositions', $positions, $lineItems);

        $this->assertSame(
            array_column($order['products'], 'variant_id'),
            array_column($shipment, 'variant_id')
        );
    }

    public function testDeliveryPositionsOnBundleChildrenAreMappedToParentOnce(): void
    {
        $bundle = $this->lineItem('bill-of-materials', 'Pizza-Set', 20.0);
        $flour = $this->lineItem('product', 'Mehl', 0.0, $bundle->getId(), $this->product('FLOUR'));
        $tomatoes = $this->lineItem('product', 'Tomaten', 0.0, $bundle->getId(), $this->product('TOMATO'));
        $lineItems = new OrderLineItemCollection([$bundle, $flour, $tomatoes]);
        $positions = new OrderDeliveryPositionCollection([$this->position($flour), $this->position($tomatoes)]);

        $order = $this->call('readLineItems', $lineItems);
        $shipment = $this->call('readDeliveryPositions', $positions, $lineItems);

        $this->assertCount(1, $order['products']);
        $this->assertSame('Pizza-Set', $order['products'][0]['title']);
        $this->assertSame(20.0, $order['products'][0]['price']);
        $this->assertCount(1, $shipment);
        $this->assertSame($order['products'][0]['variant_id'], $shipment[0]['variant_id']);
    }

    public function testBundleParentWithOwnProductKeepsItsIdentity(): void
    {
        $bundleProduct = $this->product('SO_070803_1X');
        $bundle = $this->lineItem('bill-of-materials', 'Fior di Latte', 6.89, null, $bundleProduct);
        $stock = $this->lineItem('product', '[BESTAND] Fior di Latte', 0.0, $bundle->getId(), $this->product('X'));

        $result = $this->call('readLineItems', new OrderLineItemCollection([$bundle, $stock]));

        $this->assertCount(1, $result['products']);
        $this->assertSame('SO_070803_1X', $result['products'][0]['sku']);
        $this->assertSame($bundleProduct->getId(), $result['products'][0]['variant_id']);
    }

    public function testNonProductRootWithoutProductChildrenIsIgnored(): void
    {
        $credit = $this->lineItem('credit', 'Gutschrift', -5.0);

        $result = $this->call('readLineItems', new OrderLineItemCollection([$credit]));

        $this->assertSame([], $result['products']);
        $this->assertSame(0.0, $result['subTotalPrice']);
    }
}
