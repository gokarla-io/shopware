<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Logging;

use Karla\Delivery\Logging\KarlaContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Logging\KarlaContextProcessor
 */
final class KarlaContextProcessorTest extends TestCase
{
    private KarlaContextProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new KarlaContextProcessor();
    }

    /**
     * @covers ::__invoke
     */
    public function testAddsDefaultNamespaceWhenNoComponent(): void
    {
        // Arrange: Create log record without component
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'karla_delivery',
            level: Level::Debug,
            message: 'Test message',
            context: ['key' => 'value'],
        );

        // Act: Process the record
        $processed = ($this->processor)($record);

        // Assert: Default namespace is added
        $this->assertEquals('karla', $processed->extra['namespace']);
        $this->assertEquals('karla_delivery', $processed->extra['app']);
    }

    /**
     * @covers ::__invoke
     */
    public function testAddsHierarchicalNamespaceWithComponent(): void
    {
        // Arrange: Create log record with component
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'karla_delivery',
            level: Level::Debug,
            message: 'Test message',
            context: [
                'component' => 'webhook.api',
                'key' => 'value',
            ],
        );

        // Act: Process the record
        $processed = ($this->processor)($record);

        // Assert: Hierarchical namespace is created
        $this->assertEquals('karla.webhook.api', $processed->extra['namespace']);
        $this->assertEquals('karla_delivery', $processed->extra['app']);
    }

    /**
     * @covers ::__invoke
     */
    public function testHandlesOrderComponent(): void
    {
        // Arrange: Create log record with order component
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'karla_delivery',
            level: Level::Info,
            message: 'Order synced',
            context: [
                'component' => 'order',
                'order_id' => '123',
            ],
        );

        // Act: Process the record
        $processed = ($this->processor)($record);

        // Assert: Order namespace is created
        $this->assertEquals('karla.order', $processed->extra['namespace']);
        $this->assertEquals('karla_delivery', $processed->extra['app']);
    }

    /**
     * @covers ::__invoke
     */
    public function testHandlesWebhookConfigComponent(): void
    {
        // Arrange: Create log record with webhook.config component
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'karla_delivery',
            level: Level::Debug,
            message: 'Webhook configured',
            context: [
                'component' => 'webhook.config',
                'webhook_id' => 'wh_123',
            ],
        );

        // Act: Process the record
        $processed = ($this->processor)($record);

        // Assert: Webhook config namespace is created
        $this->assertEquals('karla.webhook.config', $processed->extra['namespace']);
        $this->assertEquals('karla_delivery', $processed->extra['app']);
    }

    /**
     * @covers ::__invoke
     */
    public function testPreservesOriginalContextAndExtra(): void
    {
        // Arrange: Create log record with existing context and extra
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'karla_delivery',
            level: Level::Error,
            message: 'Error occurred',
            context: [
                'component' => 'webhook.receiver',
                'error' => 'Invalid signature',
                'request_id' => 'req_123',
            ],
            extra: [
                'existing_key' => 'existing_value',
            ],
        );

        // Act: Process the record
        $processed = ($this->processor)($record);

        // Assert: Original context is preserved
        $this->assertEquals('webhook.receiver', $processed->context['component']);
        $this->assertEquals('Invalid signature', $processed->context['error']);
        $this->assertEquals('req_123', $processed->context['request_id']);

        // Assert: Original extra is preserved and namespace is added
        $this->assertEquals('existing_value', $processed->extra['existing_key']);
        $this->assertEquals('karla.webhook.receiver', $processed->extra['namespace']);
        $this->assertEquals('karla_delivery', $processed->extra['app']);
    }
}
