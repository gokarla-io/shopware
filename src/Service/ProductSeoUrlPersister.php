<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Karla\Delivery\Message\SyncProductUrlsMessage;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Content\Seo\SeoUrlPersister;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProductSeoUrlPersister extends SeoUrlPersister
{
    public function __construct(
        private SeoUrlPersister $inner,
        private ProductSyncService $products,
        private SystemConfigService $config,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private EntityRepository $seoUrls
    ) {
    }

    public function updateSeoUrls(Context $context, string $routeName, array $foreignKeys, iterable $seoUrls, SalesChannelEntity $salesChannel): void
    {
        // The core consumes this iterable once and may replace another product's URL via SQL.
        $seoUrls = is_array($seoUrls) ? $seoUrls : iterator_to_array($seoUrls, false);
        $refresh = false;
        $batches = [];

        try {
            $channel = $salesChannel->getId();
            $refresh = $routeName === 'frontend.detail.page' && $foreignKeys !== []
                && $this->config->get('KarlaDelivery.config.productSyncEnabled', $channel)
                && $context->getLanguageId() === ($this->products->getProductUrlLanguage($channel) ?? $salesChannel->getLanguageId());
            if ($refresh) {
                $batches[$channel] = array_fill_keys($foreignKeys, true);
                $paths = [];
                foreach ($seoUrls as $url) {
                    $data = $url instanceof SeoUrlEntity ? $url->jsonSerialize() : $url;
                    if (empty($data['error']) && ! empty($data['seoPathInfo'])) {
                        $paths[$data['salesChannelId'] ?? 'all'][] = ltrim((string) $data['seoPathInfo'], '/');
                    }
                }
                foreach ($paths as $scope => $pathInfos) {
                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsFilter('routeName', 'frontend.detail.page'));
                    $criteria->addFilter(new EqualsFilter('languageId', $context->getLanguageId()));
                    $criteria->addFilter(new EqualsFilter('salesChannelId', $scope === 'all' ? null : $scope));
                    $criteria->addFilter(new EqualsFilter('isCanonical', true));
                    $criteria->addFilter(new EqualsAnyFilter('seoPathInfo', array_values(array_unique($pathInfos))));
                    /** @var SeoUrlEntity $existing */
                    foreach ($this->seoUrls->search($criteria, $context) as $existing) {
                        $batches[$existing->getSalesChannelId() ?? 'all'][$existing->getForeignKey()] = true;
                    }
                }
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to capture displaced product URLs', ['error' => $error->getMessage()]);
        }

        $this->inner->updateSeoUrls($context, $routeName, $foreignKeys, $seoUrls, $salesChannel);

        if (! $refresh) {
            return;
        }

        try {
            foreach ($batches as $scope => $ids) {
                $this->bus->dispatch(new SyncProductUrlsMessage(array_keys($ids), $scope === 'all' ? null : $scope));
            }
        } catch (\Throwable $error) {
            $this->logger->error('Unable to queue indexed product URLs', ['error' => $error->getMessage()]);
        }
    }
}
