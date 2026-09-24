<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class MigrationProtectionService
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function shouldSuppress(OrderEntity $order): bool
    {
        $channel = $order->getSalesChannelId();
        if (! $this->config->get('KarlaDelivery.config.migrationProtectionEnabled', $channel)) {
            return false;
        }

        $cutoff = $this->parseCutoff($this->config->get('KarlaDelivery.config.migrationOrderCutoff', $channel));
        if ($cutoff === null) {
            $this->logger->error('Migration protection requires a valid order cutoff; order sync and shipment flows blocked', [
                'component' => 'migration.protection',
                'sales_channel_id' => $channel,
            ]);

            return true;
        }

        if ($order->getOrderDateTime() >= $cutoff) {
            return false;
        }

        $this->logger->info('Historical order suppressed by migration protection', [
            'component' => 'migration.protection',
            'order_id' => $order->getId(),
            'sales_channel_id' => $channel,
            'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
        ]);

        return true;
    }

    private function parseCutoff(mixed $value): ?\DateTimeImmutable
    {
        // Shopware's datetime field stores ISO timestamps. Reject relative dates and missing timezones.
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return \DateTimeImmutable::getLastErrors() === false ? $date : null;
    }
}
