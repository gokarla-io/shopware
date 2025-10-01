<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Subscriber;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Karla\Delivery\Subscriber\KarlaDataStorer;
use Karla\Delivery\Tests\Fixtures\KarlaWebhookPayloads;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\FlowEventAware;

class KarlaDataStorerTest extends TestCase
{
    /**
     * @covers \Karla\Delivery\Subscriber\KarlaDataStorer::__construct
     */
    public function testConstructor(): void
    {
        // Arrange
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);

        // Act
        $storer = new KarlaDataStorer($logger);

        // Assert
        $this->assertInstanceOf(KarlaDataStorer::class, $storer);
        $this->assertInstanceOf(FlowStorer::class, $storer);
    }

    /**
     * @covers \Karla\Delivery\Subscriber\KarlaDataStorer::store
     */
    public function testStoreKarlaData(): void
    {
        // Arrange
        $context = Context::createDefaultContext();
        $event = new KarlaWebhookEvent(KarlaWebhookPayloads::shipment(), $context);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);

        // Expect debug logging with available keys and data
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'Storing Karla data for Flow Builder templates',
                $this->callback(function ($context) {
                    return isset($context['component'])
                        && isset($context['available_keys'])
                        && isset($context['data'])
                        && in_array('tracking_number', $context['available_keys']);
                })
            );

        $storer = new KarlaDataStorer($logger);
        $stored = [];

        // Act
        $result = $storer->store($event, $stored);

        // Assert
        $this->assertArrayHasKey('karla', $result);
        $this->assertIsArray($result['karla']);
        $this->assertArrayHasKey('tracking_number', $result['karla']);
        $this->assertEquals('TRACK123', $result['karla']['tracking_number']);
    }

    /**
     * @covers \Karla\Delivery\Subscriber\KarlaDataStorer::store
     */
    public function testStoreDoesNothingForNonKarlaEvents(): void
    {
        // Arrange
        // Create a mock FlowEventAware that is not a KarlaWebhookEvent
        $event = $this->createMock(FlowEventAware::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);

        // Logger should not be called for non-Karla events
        $logger->expects($this->never())->method('debug');

        $storer = new KarlaDataStorer($logger);
        $stored = ['existing' => 'data'];

        // Act
        $result = $storer->store($event, $stored);

        // Assert
        $this->assertEquals(['existing' => 'data'], $result);
        $this->assertArrayNotHasKey('karla', $result);
    }

    /**
     * @covers \Karla\Delivery\Subscriber\KarlaDataStorer::restore
     */
    public function testRestoreKarlaData(): void
    {
        // Arrange
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $storable = $this->createMock(StorableFlow::class);
        $karlaData = [
            'tracking_number' => 'TRACK123',
            'carrier_reference' => 'DHL',
        ];

        $storable->expects($this->once())
            ->method('hasStore')
            ->with('karla')
            ->willReturn(true);

        $storable->expects($this->once())
            ->method('getStore')
            ->with('karla')
            ->willReturn($karlaData);

        $storable->expects($this->once())
            ->method('setData')
            ->with('karla', $karlaData);

        $storer = new KarlaDataStorer($logger);

        // Act
        $storer->restore($storable);

        // Assert is done via mock expectations
    }

    /**
     * @covers \Karla\Delivery\Subscriber\KarlaDataStorer::restore
     */
    public function testRestoreDoesNothingWhenNoKarlaData(): void
    {
        // Arrange
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $storable = $this->createMock(StorableFlow::class);

        $storable->expects($this->once())
            ->method('hasStore')
            ->with('karla')
            ->willReturn(false);

        $storable->expects($this->never())
            ->method('getStore');

        $storable->expects($this->never())
            ->method('setData');

        $storer = new KarlaDataStorer($logger);

        // Act
        $storer->restore($storable);

        // Assert is done via mock expectations
    }
}
