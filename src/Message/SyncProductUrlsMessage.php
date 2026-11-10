<?php

declare(strict_types=1);

namespace Karla\Delivery\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class SyncProductUrlsMessage implements AsyncMessageInterface
{
    /** @param list<string> $productIds */
    public function __construct(public readonly array $productIds, public readonly ?string $salesChannelId, public readonly bool $reconcile = false, public readonly bool $refreshUrls = true)
    {
    }
}
