<?php

declare(strict_types=1);

namespace Karla\Delivery\Message;

class SyncAllProductsMessage
{
    private int $offset;
    private int $limit;

    public function __construct(int $offset = 0, int $limit = 50)
    {
        $this->offset = $offset;
        $this->limit = $limit;
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
