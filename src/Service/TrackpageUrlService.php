<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TrackpageUrlService
{
    public const CUSTOM_FIELD_NAME = 'karla_trackpage_url';
    public const CONTEXT_STATE = 'karla.trackpage_url.persist';

    private readonly string $apiUsername;
    private readonly string $apiKey;
    private readonly string $apiUrl;
    private readonly float $requestTimeout;

    public function __construct(
        SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->apiUsername = (string) ($systemConfigService->get('KarlaDelivery.config.apiUsername') ?? '');
        $this->apiKey = (string) ($systemConfigService->get('KarlaDelivery.config.apiKey') ?? '');
        $this->apiUrl = rtrim((string) ($systemConfigService->get('KarlaDelivery.config.apiUrl') ?? ''), '/');
        $this->requestTimeout = (float) ($systemConfigService->get('KarlaDelivery.config.requestTimeout') ?? 10.0);
    }

    public function ensureForOrder(OrderEntity $order, string $shopSlug, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        if (! empty($customFields[self::CUSTOM_FIELD_NAME])) {
            return;
        }

        if ($this->apiUsername === '' || $this->apiKey === '' || $this->apiUrl === '' || $shopSlug === '') {
            $this->logger->warning('Signed tracking URL skipped - missing configuration', [
                'component' => 'order.trackpage_url',
                'order_number' => $order->getOrderNumber(),
                'shop_slug' => $shopSlug,
            ]);

            return;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->apiUrl . '/v1/shops/' . rawurlencode($shopSlug) . '/orders/trackpage-url',
                [
                    'auth_basic' => [$this->apiUsername, $this->apiKey],
                    'json' => [
                        'id' => $order->getId(),
                        'id_type' => 'external_id',
                    ],
                    'timeout' => $this->requestTimeout,
                ]
            );
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode >= 400) {
                $this->logger->warning('Signed tracking URL request failed', [
                    'component' => 'order.trackpage_url',
                    'order_number' => $order->getOrderNumber(),
                    'shop_slug' => $shopSlug,
                    'status_code' => $statusCode,
                    'response_bytes' => strlen($content),
                ]);

                return;
            }

            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $trackpageUrl = is_array($payload) ? ($payload['url'] ?? null) : null;
            if (! is_string($trackpageUrl) || parse_url($trackpageUrl, PHP_URL_SCHEME) !== 'https') {
                $this->logger->warning('Signed tracking URL response was invalid', [
                    'component' => 'order.trackpage_url',
                    'order_number' => $order->getOrderNumber(),
                    'shop_slug' => $shopSlug,
                ]);

                return;
            }

            $context->addState(self::CONTEXT_STATE);

            try {
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'versionId' => Defaults::LIVE_VERSION,
                        'customFields' => [
                            ...$customFields,
                            self::CUSTOM_FIELD_NAME => $trackpageUrl,
                        ],
                    ],
                ], $context);
            } finally {
                $context->removeState(self::CONTEXT_STATE);
            }

            $this->logger->info('Signed tracking URL stored for order', [
                'component' => 'order.trackpage_url',
                'order_number' => $order->getOrderNumber(),
                'shop_slug' => $shopSlug,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Signed tracking URL could not be stored', [
                'component' => 'order.trackpage_url',
                'order_number' => $order->getOrderNumber(),
                'shop_slug' => $shopSlug,
                'error_class' => $exception::class,
            ]);
        }
    }
}
