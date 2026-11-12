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
        $item->setPosition(1);
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
    private function bundleOrderLineItems(): array
    {
        $single = $this->lineItem('product', 'Single Product', 9.99, null, $this->product('SINGLE-1'));
        $bundle = $this->lineItem('bill-of-materials', 'Bundle Product', 6.89);
        $stock = $this->lineItem(
            'product',
            'Stückliste BUNDLE-1X: [BESTAND] Bundle Product',
            0.0,
            $bundle->getId(),
            $this->product('BUNDLE-STOCK'),
        );

        return [new OrderLineItemCollection([$single, $bundle, $stock]), $single, $bundle, $stock];
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
        [$lineItems, , $bundle, $stock] = $this->bundleOrderLineItems();

        $result = $this->call('readLineItems', $lineItems);

        $this->assertCount(2, $result['products']);
        $bundleProduct = $result['products'][1];
        $this->assertSame($bundle->getLabel(), $bundleProduct['title']);
        $this->assertSame(6.89, $bundleProduct['price']);
        // The parent keeps its own referenced ID, so the backend never resolves the stock child.
        $this->assertSame($bundle->getReferencedId(), $bundleProduct['variant_id']);
        $this->assertNotSame($stock->getReferencedId(), $bundleProduct['variant_id']);
        $this->assertNotContains('BUNDLE-STOCK', array_column($result['products'], 'sku'));
        $this->assertEqualsWithDelta(16.88, $result['subTotalPrice'], 0.0001);
    }

    public function testDeliveryPositionOnBundleParentMatchesOrderProducts(): void
    {
        [$lineItems, $single, $bundle] = $this->bundleOrderLineItems();
        $positions = new OrderDeliveryPositionCollection([$this->position($single), $this->position($bundle)]);

        $order = $this->call('readLineItems', $lineItems);
        $shipment = $this->call('readDeliveryPositions', $positions, $order);

        $this->assertSame(
            array_column($order['products'], 'variant_id'),
            array_column($shipment, 'variant_id')
        );
    }

    public function testDeliveryPositionsOnBundleChildrenAreMappedToParentOnce(): void
    {
        $bundle = $this->lineItem('bill-of-materials', 'Multi-Part Set', 20.0);
        $partA = $this->lineItem('product', 'Part A', 0.0, $bundle->getId(), $this->product('PART-A'));
        $partB = $this->lineItem('product', 'Part B', 0.0, $bundle->getId(), $this->product('PART-B'));
        $lineItems = new OrderLineItemCollection([$bundle, $partA, $partB]);
        $positions = new OrderDeliveryPositionCollection([$this->position($partA), $this->position($partB)]);

        $order = $this->call('readLineItems', $lineItems);
        $shipment = $this->call('readDeliveryPositions', $positions, $order);

        $this->assertCount(1, $order['products']);
        $this->assertSame('Multi-Part Set', $order['products'][0]['title']);
        $this->assertSame(20.0, $order['products'][0]['price']);
        $this->assertCount(1, $shipment);
        $this->assertSame($order['products'][0]['variant_id'], $shipment[0]['variant_id']);
    }

    public function testBundleParentWithOwnProductKeepsItsIdentity(): void
    {
        $bundleProduct = $this->product('BUNDLE-1X');
        $bundle = $this->lineItem('bill-of-materials', 'Bundle Product', 6.89, null, $bundleProduct);
        $stock = $this->lineItem('product', '[BESTAND] Bundle Product', 0.0, $bundle->getId(), $this->product('X'));

        $result = $this->call('readLineItems', new OrderLineItemCollection([$bundle, $stock]));

        $this->assertCount(1, $result['products']);
        $this->assertSame('BUNDLE-1X', $result['products'][0]['sku']);
        $this->assertSame($bundleProduct->getId(), $result['products'][0]['variant_id']);
    }

    public function testNonProductRootWithoutProductChildrenIsIgnored(): void
    {
        $credit = $this->lineItem('credit', 'Gutschrift', -5.0);

        $result = $this->call('readLineItems', new OrderLineItemCollection([$credit]));

        $this->assertSame([], $result['products']);
        $this->assertSame(0.0, $result['subTotalPrice']);
    }

    public function testDeliveryPositionWithoutLineItemIsSkipped(): void
    {
        [$lineItems, $single] = $this->bundleOrderLineItems();
        $orphan = new OrderDeliveryPositionEntity();
        $orphan->setId(Uuid::randomHex());
        $positions = new OrderDeliveryPositionCollection([$orphan, $this->position($single)]);

        $shipment = $this->call('readDeliveryPositions', $positions, $this->call('readLineItems', $lineItems));

        $this->assertSame(['SINGLE-1'], array_column($shipment, 'sku'));
    }

    public function testSkuFallsBackToPayloadProductNumberWithoutProductEntity(): void
    {
        $item = $this->lineItem('product', 'Unloaded Product', 4.5);
        $item->setPayload(['productNumber' => 'PAYLOAD-SKU']);

        $result = $this->call('readLineItems', new OrderLineItemCollection([$item]));

        $this->assertSame('PAYLOAD-SKU', $result['products'][0]['sku']);
    }

    public function testDeliveryPositionOnIntermediateBundleResolvesToRoot(): void
    {
        $outer = $this->lineItem('bill-of-materials', 'Outer Set', 30.0);
        $inner = $this->lineItem('bill-of-materials', 'Inner Set', 0.0, $outer->getId());
        $part = $this->lineItem('product', 'Part', 0.0, $inner->getId(), $this->product('PART-1'));
        $lineItems = new OrderLineItemCollection([$outer, $inner, $part]);
        $positions = new OrderDeliveryPositionCollection([$this->position($inner)]);

        $order = $this->call('readLineItems', $lineItems);
        $shipment = $this->call('readDeliveryPositions', $positions, $order);

        $this->assertCount(1, $order['products']);
        $this->assertSame('Outer Set', $order['products'][0]['title']);
        $this->assertSame($order['products'], $shipment);
    }

    public function testParentCycleDoesNotLoopForever(): void
    {
        $a = $this->lineItem('product', 'A', 1.0, null, $this->product('A'));
        $b = $this->lineItem('product', 'B', 2.0, $a->getId(), $this->product('B'));
        $a->setParentId($b->getId());

        $result = $this->call('readLineItems', new OrderLineItemCollection([$a, $b]));

        // Terminates, and each item in the cycle is its own root, counted exactly once.
        $this->assertEqualsCanonicalizing(['A', 'B'], array_column($result['products'], 'sku'));
        $this->assertSame([$a->getId() => $a->getId(), $b->getId() => $b->getId()], $result['rootIdByLineItemId']);
        $this->assertEqualsWithDelta(3.0, $result['subTotalPrice'], 0.0001);
    }

    public function testMultiPartBundleWithoutReferencedIdBorrowsFirstPartIdentity(): void
    {
        $bundle = $this->lineItem('bill-of-materials', 'Multi-Part Set', 20.0);
        $bundle->setReferencedId(null);
        $partA = $this->lineItem('product', 'Part A', 0.0, $bundle->getId(), $this->product('PART-A'));
        $partB = $this->lineItem('product', 'Part B', 0.0, $bundle->getId(), $this->product('PART-B'));

        $result = $this->call('readLineItems', new OrderLineItemCollection([$bundle, $partA, $partB]));

        $this->assertCount(1, $result['products']);
        $this->assertSame('Multi-Part Set', $result['products'][0]['title']);
        $this->assertSame(20.0, $result['products'][0]['price']);
        $this->assertSame('PART-A', $result['products'][0]['sku']);
        $this->assertSame($partA->getReferencedId(), $result['products'][0]['variant_id']);
    }

    public function testMultiPartBundleIdentityFollowsPositionNotCollectionOrder(): void
    {
        $bundle = $this->lineItem('bill-of-materials', 'Multi-Part Set', 20.0);
        $bundle->setReferencedId(null);
        $partA = $this->lineItem('product', 'Part A', 0.0, $bundle->getId(), $this->product('PART-A'));
        $partA->setPosition(1);
        $partB = $this->lineItem('product', 'Part B', 0.0, $bundle->getId(), $this->product('PART-B'));
        $partB->setPosition(2);

        $result = $this->call('readLineItems', new OrderLineItemCollection([$bundle, $partB, $partA]));

        $this->assertSame('PART-A', $result['products'][0]['sku']);
    }

    public function testBundleParentWithoutReferencedIdBorrowsChildIdentity(): void
    {
        $bundle = $this->lineItem('bill-of-materials', 'Bundle Product', 6.89);
        $bundle->setReferencedId(null);
        $stock = $this->lineItem('product', '[BESTAND] Bundle Product', 0.0, $bundle->getId(), $this->product('STOCK'));

        $result = $this->call('readLineItems', new OrderLineItemCollection([$bundle, $stock]));

        $this->assertSame('Bundle Product', $result['products'][0]['title']);
        $this->assertSame('STOCK', $result['products'][0]['sku']);
        $this->assertSame($stock->getReferencedId(), $result['products'][0]['variant_id']);
    }
}
