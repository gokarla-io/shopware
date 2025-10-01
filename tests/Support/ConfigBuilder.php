<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Support;

/**
 * Builder for creating SystemConfig mock configurations
 *
 * Usage:
 *   $configMap = ConfigBuilder::create()
 *       ->withApiConfig('testSlug', 'testUser', 'testKey')
 *       ->withAllOrderStatusesEnabled()
 *       ->buildMap();
 */
class ConfigBuilder
{
    private array $config = [];

    private function __construct()
    {
        // Set defaults
        $this->withApiConfig('testSlug', 'testUser', 'testKey', 'https://api.example.com');
        $this->withDefaultOrderStatuses();
        $this->withDefaultDeliveryStatuses();
        $this->withDefaultMappings();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withApiConfig(
        string $shopSlug,
        string $username,
        string $apiKey,
        string $apiUrl = 'https://api.example.com',
        float $timeout = 10.5
    ): self {
        $this->config['KarlaDelivery.config.shopSlug'] = $shopSlug;
        $this->config['KarlaDelivery.config.apiUsername'] = $username;
        $this->config['KarlaDelivery.config.apiKey'] = $apiKey;
        $this->config['KarlaDelivery.config.apiUrl'] = $apiUrl;
        $this->config['KarlaDelivery.config.requestTimeout'] = $timeout;

        return $this;
    }

    public function withMissingApiConfig(): self
    {
        $this->config['KarlaDelivery.config.shopSlug'] = '';
        $this->config['KarlaDelivery.config.apiKey'] = '';

        return $this;
    }

    public function withDefaultOrderStatuses(): self
    {
        $this->config['KarlaDelivery.config.orderOpen'] = false;
        $this->config['KarlaDelivery.config.orderInProgress'] = true;
        $this->config['KarlaDelivery.config.orderCompleted'] = false;
        $this->config['KarlaDelivery.config.orderCancelled'] = false;

        return $this;
    }

    public function withAllOrderStatusesEnabled(): self
    {
        $this->config['KarlaDelivery.config.orderOpen'] = true;
        $this->config['KarlaDelivery.config.orderInProgress'] = true;
        $this->config['KarlaDelivery.config.orderCompleted'] = true;
        $this->config['KarlaDelivery.config.orderCancelled'] = true;

        return $this;
    }

    public function withDefaultDeliveryStatuses(): self
    {
        $this->config['KarlaDelivery.config.deliveryOpen'] = false;
        $this->config['KarlaDelivery.config.deliveryShipped'] = true;
        $this->config['KarlaDelivery.config.deliveryShippedPartially'] = true;
        $this->config['KarlaDelivery.config.deliveryReturned'] = false;
        $this->config['KarlaDelivery.config.deliveryReturnedPartially'] = false;
        $this->config['KarlaDelivery.config.deliveryCancelled'] = false;

        return $this;
    }

    public function withAllDeliveryStatusesEnabled(): self
    {
        $this->config['KarlaDelivery.config.deliveryOpen'] = true;
        $this->config['KarlaDelivery.config.deliveryShipped'] = true;
        $this->config['KarlaDelivery.config.deliveryShippedPartially'] = true;
        $this->config['KarlaDelivery.config.deliveryReturned'] = true;
        $this->config['KarlaDelivery.config.deliveryReturnedPartially'] = true;
        $this->config['KarlaDelivery.config.deliveryCancelled'] = true;

        return $this;
    }

    public function withDefaultMappings(): self
    {
        $this->config['KarlaDelivery.config.depositLineItemType'] = '';
        $this->config['KarlaDelivery.config.salesChannelMapping'] = '';

        return $this;
    }

    public function withDepositLineItemType(string $type): self
    {
        $this->config['KarlaDelivery.config.depositLineItemType'] = $type;

        return $this;
    }

    public function withSalesChannelMapping(string $salesChannelId, string $shopSlug): self
    {
        $this->config['KarlaDelivery.config.salesChannelMapping'] = "{$salesChannelId}:{$shopSlug}";

        return $this;
    }

    /**
     * Build the config as a map for willReturnMap()
     */
    public function buildMap(): array
    {
        $map = [];
        foreach ($this->config as $key => $value) {
            $map[] = [$key, null, $value];
        }

        return $map;
    }
}
