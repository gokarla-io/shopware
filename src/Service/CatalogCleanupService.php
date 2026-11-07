<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

class CatalogCleanupService
{
    public function __construct(private EntityRepository $products, private EntityRepository $channels, private SystemConfigService $config, private ProductSyncService $sync, private CatalogApiClient $api, private Connection $connection)
    {
    }

    public function withCatalogLock(?string $channel, callable $work): void
    {
        // Named locks serialize workers across PHP processes without locking product rows.
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $key = hash('sha256', 'karla-catalog:' . $this->endpoint($channel));
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$key]) !== 1) {
            throw new RecoverableMessageHandlingException('Another catalog refresh is running', retryDelay: 5000);
        }

        try {
            $work();
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    /** @return list<string> */
    public function catalogVariantIds(?string $channel): array
    {
        $ids = [];
        foreach ($this->catalogRows($channel) as $row) {
            if (Uuid::isValid($row['variant_id'])) {
                $ids[$row['variant_id']] = $row['variant_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Retire stale existing rows, returning only eligible leaf IDs for the subsequent upsert.
     * @param list<string> $ids
     * @return list<string>
     */
    public function retireUnavailable(array $ids, ?string $channel): array
    {
        if ($ids === []) {
            return [];
        }
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $known = [];
        $parents = [];
        /** @var ProductEntity $product */
        foreach ($this->products->search(new Criteria($ids), $context) as $product) {
            $known[$product->getId()] = true;
            if ($product->getParentId() === null && $product->getChildCount() > 0) {
                $parents[$product->getId()] = true;
            }
        }
        $children = new Criteria();
        $children->addFilter(new EqualsAnyFilter('parentId', $ids));
        /** @var ProductEntity $child */
        foreach ($this->products->search($children, $context) as $child) {
            $known[$child->getId()] = true;
            $parents[$child->getParentId()] = true;
        }
        // Unknown/manual catalog entries are never inferred to be deleted native products.
        if ($known === []) {
            return [];
        }
        $destinationChannels = $this->destinationChannels($channel);
        if ($destinationChannels === []) {
            return [];
        }
        $eligible = new Criteria(array_keys($known));
        $eligible->addFilter(new EqualsFilter('active', true));
        $eligible->addFilter(new EqualsAnyFilter('visibilities.salesChannelId', $destinationChannels));
        $keep = [];
        $eligibleIds = [];
        /** @var ProductEntity $product */
        foreach ($this->products->search($eligible, $context) as $product) {
            if (! isset($parents[$product->getId()])) {
                $keep[($product->getParentId() ?? $product->getId()) . ':' . $product->getId()] = true;
                $eligibleIds[] = $product->getId();
            }
        }
        foreach (array_chunk(array_keys($known), 100) as $batch) {
            // Read every page before deleting, so offset pagination cannot skip our own removals.
            $rows = $this->catalogRows($channel, $batch);
            foreach ($rows as $row) {
                if (! isset($keep[$row['product_id'] . ':' . $row['variant_id']])) {
                    $url = $this->endpoint($channel) . '/' . rawurlencode($row['product_id']) . '/variants/' . rawurlencode($row['variant_id']);
                    $this->api->request($url . '/archive', 'POST', [], $channel);
                }
            }
        }
        if ($channel === null) {
            return $eligibleIds;
        }
        if (! in_array($channel, $destinationChannels, true) || $eligibleIds === []) {
            return [];
        }
        if (count($destinationChannels) === 1) {
            return $eligibleIds;
        }
        $current = new Criteria($eligibleIds);
        $current->addFilter(new EqualsFilter('active', true));
        $current->addFilter(new EqualsFilter('visibilities.salesChannelId', $channel));
        $writeIds = [];
        /** @var ProductEntity $product */
        foreach ($this->products->search($current, $context) as $product) {
            $writeIds[] = $product->getId();
        }

        return $writeIds;
    }

    /** @return list<string> */
    private function destinationChannels(?string $channel): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $slug = $this->sync->getShopSlug($channel);
        $apiUrl = rtrim((string) $this->config->get('KarlaDelivery.config.apiUrl', $channel), '/');
        $ids = [];
        /** @var SalesChannelEntity $storefront */
        foreach ($this->channels->search($criteria, Context::createDefaultContext()) as $storefront) {
            $id = $storefront->getId();
            if ($this->config->get('KarlaDelivery.config.productSyncEnabled', $id)
                && $this->sync->getShopSlug($id) === $slug
                && rtrim((string) $this->config->get('KarlaDelivery.config.apiUrl', $id), '/') === $apiUrl) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function endpoint(?string $channel): string
    {
        $url = (string) $this->config->get('KarlaDelivery.config.apiUrl', $channel);
        $slug = $this->sync->getShopSlug($channel);
        if ($url === '' || $slug === '') {
            throw new \RuntimeException('Missing catalog destination');
        }

        return rtrim($url, '/') . '/v1/shops/' . rawurlencode($slug) . '/products';
    }

    /**
     * @param list<string>|null $ids
     * @return list<array{product_id: string, variant_id: string}>
     */
    private function catalogRows(?string $channel, ?array $ids = null): array
    {
        $rows = [];
        $page = 1;
        do {
            $query = 'per_page=100&page=' . $page;
            foreach ($ids ?? [] as $id) {
                // FastAPI expects repeated query keys, not PHP-style bracketed arrays.
                $query .= '&variant_ids=' . rawurlencode($id);
            }
            $response = $this->api->request($this->endpoint($channel) . '?' . $query, 'GET', [], $channel);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ! array_is_list($body)) {
                throw new \RuntimeException('Invalid catalog list response');
            }
            foreach ($body as $row) {
                if (! is_array($row) || ! is_string($row['product_id'] ?? null) || ! is_string($row['variant_id'] ?? null)
                    || ($ids !== null && ! in_array($row['variant_id'], $ids, true))) {
                    throw new \RuntimeException('Catalog list returned an invalid or unrequested variant');
                }
                $rows[] = ['product_id' => $row['product_id'], 'variant_id' => $row['variant_id']];
            }
            ++$page;
        } while (count($body) === 100);

        return $rows;
    }
}
