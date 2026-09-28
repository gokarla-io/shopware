<?php

declare(strict_types=1);

namespace Karla\Delivery\Message;

class SyncAllProductsMessage
{
    private int $offset;
    private int $limit;
    private ?string $salesChannelId = null;

    public function __construct(int $offset = 0, int $limit = 50, ?string $salesChannelId = null)
    {
        $this->offset = $offset;
        $this->limit = $limit;
        $this->salesChannelId = $salesChannelId;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
