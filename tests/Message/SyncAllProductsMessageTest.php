<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Message;

use Karla\Delivery\Message\SyncAllProductsMessage;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \Karla\Delivery\Message\SyncAllProductsMessage
 */
final class SyncAllProductsMessageTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::getOffset
     * @covers ::getLimit
     */
    public function testMessageWithDefaultValues(): void
    {
        $message = new SyncAllProductsMessage();

        $this->assertSame(0, $message->getOffset());
        $this->assertSame(50, $message->getLimit());
    }

    /**
     * @covers ::__construct
     * @covers ::getOffset
     * @covers ::getLimit
     */
    public function testMessageWithCustomValues(): void
    {
        $message = new SyncAllProductsMessage(offset: 200, limit: 50);

        $this->assertSame(200, $message->getOffset());
        $this->assertSame(50, $message->getLimit());
    }
}
