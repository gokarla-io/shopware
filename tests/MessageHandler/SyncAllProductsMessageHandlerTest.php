<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\MessageHandler;

use Karla\Delivery\Message\SyncAllProductsMessage;
use Karla\Delivery\MessageHandler\SyncAllProductsMessageHandler;
use Karla\Delivery\Service\ProductSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\MessageHandler\SyncAllProductsMessageHandler
 */
final class SyncAllProductsMessageHandlerTest extends TestCase
{
    /** @var ProductSyncService&MockObject */
    private ProductSyncService $productSyncServiceMock;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $loggerMock;

    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $messageBusMock;

    /** @var SystemConfigService&MockObject */
    private SystemConfigService $systemConfigServiceMock;

    private SyncAllProductsMessageHandler $handler;

    protected function setUp(): void
    {
        $this->productSyncServiceMock = $this->createMock(ProductSyncService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->messageBusMock = $this->createMock(MessageBusInterface::class);
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);

        $this->handler = new SyncAllProductsMessageHandler(
            $this->productSyncServiceMock,
            $this->loggerMock,
            $this->messageBusMock,
            $this->systemConfigServiceMock
        );
    }

    /**
     * @covers ::__construct
     * @covers ::__invoke
     */
    public function testHandleMessageWithMoreProducts(): void
    {
        $message = new SyncAllProductsMessage(offset: 0, limit: 50);

        // Mock service returns true (more products exist)
        $this->productSyncServiceMock->expects($this->once())
            ->method('syncProductBatch')
            ->with(0, 50)
            ->willReturn(true);

        // Expect next message to be dispatched
        $this->messageBusMock->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($nextMessage) {
                return $nextMessage instanceof SyncAllProductsMessage
                    && $nextMessage->getOffset() === 50
                    && $nextMessage->getLimit() === 50;
            }))
            ->willReturnCallback(function ($message) {
                return new \Symfony\Component\Messenger\Envelope($message);
            });

        // Expect info logs (2 from handler: processing + dispatched)
        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $context) {
                // All logs should have product.bulk_sync component
                $this->assertEquals('product.bulk_sync', $context['component']);

                if ($message === 'Processing product bulk sync batch') {
                    $this->assertEquals(0, $context['offset']);
                } elseif ($message === 'Dispatched next product sync batch') {
                    $this->assertEquals(50, $context['next_offset']);
                }

                return null;
            });

        // Act
        ($this->handler)($message);
    }

    /**
     * @covers ::__invoke
     */
    public function testHandleMessageCompleted(): void
    {
        $message = new SyncAllProductsMessage(offset: 450, limit: 50);

        // Mock service returns false (no more products)
        $this->productSyncServiceMock->expects($this->once())
            ->method('syncProductBatch')
            ->with(450, 50)
            ->willReturn(false);

        // Should NOT dispatch next message
        $this->messageBusMock->expects($this->never())
            ->method('dispatch');

        // Expect status to be set to 'completed'
        $this->systemConfigServiceMock->expects($this->once())
            ->method('set')
            ->with('KarlaDelivery.config.productSyncStatus', 'completed');

        // Expect completion log
        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $context) {
                if ($message === 'Product bulk sync completed') {
                    $this->assertEquals('product.bulk_sync', $context['component']);
                    $this->assertEquals(500, $context['total_processed']);
                }

                return null;
            });

        // Act
        ($this->handler)($message);
    }

    /**
     * @covers ::__invoke
     */
    public function testHandleMessageWithError(): void
    {
        $message = new SyncAllProductsMessage(offset: 0, limit: 100);

        // Mock service throws exception
        $this->productSyncServiceMock->expects($this->once())
            ->method('syncProductBatch')
            ->willThrowException(new \Exception('Database error'));

        // Expect status to be set to 'failed'
        $this->systemConfigServiceMock->expects($this->once())
            ->method('set')
            ->with('KarlaDelivery.config.productSyncStatus', 'failed');

        // Expect error log
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Error during product bulk sync batch',
                $this->callback(function ($context) {
                    return $context['component'] === 'product.bulk_sync'
                        && $context['error'] === 'Database error'
                        && isset($context['file'])
                        && isset($context['line'])
                        && isset($context['trace'])
                        && $context['offset'] === 0;
                })
            );

        // Act
        ($this->handler)($message);
    }
}
