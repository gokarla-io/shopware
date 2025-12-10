<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class OrderSubscriber
 * @package Karla\Delivery\Subscriber
 */
class OrderSubscriber implements EventSubscriberInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /**
     * @var HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * @var string
     */
    private string $shopSlug;

    /**
     * @var string
     */
    private string $apiUsername;

    /**
     * @var string
     */
    private string $apiKey;

    /**
     * @var string
     */
    private string $apiUrl;

    /**
     * @var float
     */
    private float $requestTimeout;

    /**
     * @var array
     */
    private array $allowedOrderStatuses;

    /**
     * @var array
     */
    private array $allowedDeliveryStatuses;

    /**
     * @var string
     */
    private string $depositLineItemType;

    /**
     * @var array
     */
    private array $salesChannelMapping;

    /**
     * @var bool
     */
    private bool $debugMode;

    /**
     * OrderSubscriber constructor.
     * @param SystemConfigService $systemConfigService
     * @param LoggerInterface $logger
     * @param EntityRepository $orderRepository
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $orderRepository,
        HttpClientInterface $httpClient,
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->httpClient = $httpClient;

        // General Configuration
        $this->debugMode = $systemConfigService->get('KarlaDelivery.config.debugMode') ?? false;

        // API Configuration
        $this->shopSlug = $systemConfigService->get('KarlaDelivery.config.shopSlug') ?? '';
        $this->apiUsername = $systemConfigService->get('KarlaDelivery.config.apiUsername') ?? '';
        $this->apiKey = $systemConfigService->get('KarlaDelivery.config.apiKey') ?? '';
        $this->apiUrl = $systemConfigService->get('KarlaDelivery.config.apiUrl') ?? '';
        $this->requestTimeout = $systemConfigService->get('KarlaDelivery.config.requestTimeout') ?? 10.0;

        // Order Statuses Configuration
        $orderOpen = $systemConfigService->get(
            'KarlaDelivery.config.orderOpen'
        ) ?? false;
        $orderInProgress = $systemConfigService->get(
            'KarlaDelivery.config.orderInProgress'
        ) ?? false;
        $orderCompleted = $systemConfigService->get(
            'KarlaDelivery.config.orderCompleted'
        ) ?? false;
        $orderCancelled = $systemConfigService->get(
            'KarlaDelivery.config.orderCancelled'
        ) ?? false;
        if ($orderOpen) {
            $this->allowedOrderStatuses[] = 'open';
        }
        if ($orderInProgress) {
            $this->allowedOrderStatuses[] = 'in_progress';
        }
        if ($orderCompleted) {
            $this->allowedOrderStatuses[] = 'completed';
        }
        if ($orderCancelled) {
            $this->allowedOrderStatuses[] = 'cancelled';
        }

        // Delivery Statuses Configuration
        $deliveryOpen = $systemConfigService->get(
            'KarlaDelivery.config.deliveryOpen'
        ) ?? false;
        $deliveryShipped = $systemConfigService->get(
            'KarlaDelivery.config.deliveryShipped'
        ) ?? false;
        $deliveryShippedPartially = $systemConfigService->get(
            'KarlaDelivery.config.deliveryShippedPartially'
        ) ?? false;
        $deliveryReturned = $systemConfigService->get(
            'KarlaDelivery.config.deliveryReturned'
        ) ?? false;
        $deliveryReturnedPartially = $systemConfigService->get(
            'KarlaDelivery.config.deliveryReturnedPartially'
        ) ?? false;
        $deliveryCancelled = $systemConfigService->get(
            'KarlaDelivery.config.deliveryCancelled'
        ) ?? false;
        if ($deliveryOpen) {
            $this->allowedDeliveryStatuses[] = 'open';
        }
        if ($deliveryShipped) {
            $this->allowedDeliveryStatuses[] = 'shipped';
        }
        if ($deliveryShippedPartially) {
            $this->allowedDeliveryStatuses[] = 'shipped_partially';
        }
        if ($deliveryReturned) {
            $this->allowedDeliveryStatuses[] = 'returned';
        }
        if ($deliveryReturnedPartially) {
            $this->allowedDeliveryStatuses[] = 'returned_partially';
        }
        if ($deliveryCancelled) {
            $this->allowedDeliveryStatuses[] = 'cancelled';
        }

        // Mappings Configuration
        $this->depositLineItemType = $systemConfigService->get(
            'KarlaDelivery.config.depositLineItemType'
        );

        // Sales Channel Mapping Configuration
        $salesChannelMappingConfig = $systemConfigService->get(
            'KarlaDelivery.config.salesChannelMapping'
        ) ?? '';
        $this->salesChannelMapping = $this->parseSalesChannelMapping($salesChannelMappingConfig);

        // Log warnings if configuration values are missing
        if (empty($this->shopSlug) || empty($this->apiKey) || empty($this->apiUrl)) {
            $this->logger->warning('Missing critical configuration values', [
                'component' => 'order.config',
                'missing_fields' => array_filter([
                    'shopSlug' => empty($this->shopSlug),
                    'apiKey' => empty($this->apiKey),
                    'apiUrl' => empty($this->apiUrl),
                ]),
            ]);
        }
    }

    /**
     * Listen for order events
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
        OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    /**
     * Handle the order written event
     * @param EntityWrittenEvent $event
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        try {
            if (empty($this->shopSlug) || empty($this->apiUsername) || empty($this->apiKey) || empty($this->apiUrl)) {
                $this->logger->warning('Order sync skipped - missing configuration', [
                    'component' => 'order.sync',
                ]);

                return;
            }

            $context = $event->getContext();
            $orderIds = $event->getIds();

            $criteria = new Criteria($orderIds);
            $criteria->addAssociations([
                'addresses.country',
                'addresses.countryState',
                'currency',
                'deliveries.positions.orderLineItem.product',
                'deliveries.positions',
                'deliveries.stateMachineState',
                'deliveries.trackingCodes',
                'lineItems.product.cover.media',
                'lineItems.product.cover',
                'lineItems.product',
                'orderCustomer',
                'orderCustomer.customer',
                'orderCustomer.customer.group',
                'orderCustomer.customer.tags',
                'salesChannel',
                'stateMachineState',
                'tags',
                'transactions.stateMachineState',
            ]);

            $orders = $this->orderRepository->search($criteria, $context);
            /** @var OrderEntity $order */
            foreach ($orders as $order) {
                $deliveries = $order->getDeliveries();
                $this->sendKarlaOrder($order, $deliveries, $event);
            }
        } catch (\Throwable $t) {
            $this->logger->error('Unexpected error during order sync', [
                'component' => 'order.sync',
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
                'trace' => $t->getTraceAsString(),
            ]);
        }
    }

    /**
     * Upsert and optionally fulfill an order through Karla's API
     * @param OrderEntity $order
     * @param OrderDeliveryCollection $deliveries Array of OrderDeliveryEntity objects
     */
    private function sendKarlaOrder(
        OrderEntity $order,
        OrderDeliveryCollection $deliveries,
        EntityWrittenEvent $event
    ): void {
        $orderNumber = $order->getOrderNumber();
        $orderStatus = $order->getStateMachineState()->getTechnicalName();

        if (! in_array($orderStatus, $this->allowedOrderStatuses, true)) {
            $this->logger->info('Order skipped - status not allowed', [
                'component' => 'order.sync',
                'order_number' => $orderNumber,
                'order_status' => $orderStatus,
                'allowed_statuses' => $this->allowedOrderStatuses,
            ]);

            return;
        }

        $customer = $order->getOrderCustomer();
        $customerEmail = $customer ? $customer->getEmail() : null;
        $customerId = $customer && $customer->getCustomer() ? $customer->getCustomer()->getId() : null;

        $currency = $order->getCurrency();
        $currencyCode = $currency ? $currency->getIsoCode() : null;

        $lineItemDetails = $this->readLineItems($order->getLineItems());

        // Extract tags from the order and format them as segments
        $segments = $this->extractOrderTagsAsSegments($order);

        // Extract attribution data (affiliate and campaign codes)
        $affiliateCode = $order->getAffiliateCode();
        $campaignCode = $order->getCampaignCode();

        // Build order analytics object if attribution data exists
        $orderAnalytics = null;
        if ($affiliateCode || $campaignCode) {
            $orderAnalytics = [];
            if ($affiliateCode) {
                $orderAnalytics['affiliate_code'] = $affiliateCode;
            }
            if ($campaignCode) {
                $orderAnalytics['campaign_code'] = $campaignCode;
            }
        }

        $orderUpsertPayload = [
         'id' => $orderNumber,
         'id_type' => 'order_number',
         'order' => [
                'order_number' => $orderNumber,
                'order_placed_at' => $order->getCreatedAt()->format(DateTimeInterface::ATOM),
                'products' => $lineItemDetails['products'],
                'total_order_price' => $order->getPrice()->getTotalPrice(),
                'shipping_price' => $order->getShippingTotal(),
                'sub_total_price' => $lineItemDetails['subTotalPrice'],
                'discount_price' => $lineItemDetails['discountPrice'],
                'discounts' => $lineItemDetails['discounts'],
                'email_id' => $customerEmail,
                'address' => ($address = $order->getAddresses()->first()) ? $this->readAddress($address) : null,
                'currency' => $currencyCode,
                'external_id' => $order->getId(),
                'external_customer_id' => $customerId,
                'segments' => $segments,
            ],
         'trackings' => [],
        ];

        // Add order_analytics if attribution data exists
        if ($orderAnalytics) {
            $orderUpsertPayload['order_analytics'] = $orderAnalytics;
        }
        $nDeliveries = 0;
        foreach ($deliveries as $delivery) {
            $deliveryStatus = $delivery->getStateMachineState()->getTechnicalName();
            if (! in_array($deliveryStatus, $this->allowedDeliveryStatuses, true)) {
                $this->logger->info('Delivery skipped - status not allowed', [
                    'component' => 'order.sync',
                    'order_number' => $orderNumber,
                    'delivery_status' => $deliveryStatus,
                    'allowed_statuses' => $this->allowedDeliveryStatuses,
                ]);

                continue;
            }
            $trackingCodes = $delivery->getTrackingCodes();
            if (empty($trackingCodes)) {
                $this->logger->info('Delivery skipped - no tracking codes', [
                    'component' => 'order.sync',
                    'order_number' => $orderNumber,
                ]);

                continue;
            }

            $deliveryProducts = $this->readDeliveryPositions($delivery->getPositions());
            foreach ($trackingCodes as $trackingNumber) {
                if ($this->debugMode) {
                    $this->logger->debug('Delivery found with tracking number', [
                        'component' => 'order.sync',
                        'order_number' => $orderNumber,
                        'tracking_number' => $trackingNumber,
                    ]);
                }
                $orderUpsertPayload['trackings'][] = [
                    'tracking_number' => $trackingNumber,
                    'tracking_placed_at' => (new \DateTime())->format(\DateTime::ATOM),
                    'products' => $deliveryProducts,
                ];
                $nDeliveries++;
            }
        }

        $shopSlug = $this->getShopSlugForSalesChannel($order->getSalesChannelId());
        $url = $this->apiUrl . '/v1/shops/' . $shopSlug . '/orders';
        $this->sendRequestToKarlaApi($url, 'PUT', $orderUpsertPayload);

        $this->logger->info('Order synced to Karla successfully', [
            'component' => 'order.sync',
            'order_number' => $orderNumber,
            'deliveries_count' => $nDeliveries,
            'segments_count' => count($segments),
            'segments' => ! empty($segments) ? $segments : null,
        ]);
    }

    /**
     * Send request to Karla's API
     *
     * @param string $url
     * @param string $method
     * @param array $orderData
     */
    private function sendRequestToKarlaApi(string $url, string $method, array $orderData): void
    {
        $jsonPayload = json_encode($orderData);
        $auth = base64_encode($this->apiUsername . ':' . $this->apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        if ($this->debugMode) {
            $this->logger->debug('Preparing API request to Karla', [
                'component' => 'order.api',
                'method' => $method,
                'url' => $url,
                'payload' => $orderData,
            ]);
        }

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $jsonPayload,
                'timeout' => $this->requestTimeout,
            ]);

            // Get status code (can throw on network errors)
            $statusCode = $response->getStatusCode();

            // Get content - use false parameter to prevent throwing on HTTP error status codes
            $content = $response->getContent(false);

            // Log the response
            if ($this->debugMode) {
                $this->logger->debug('API request to Karla completed', [
                    'component' => 'order.api',
                    'method' => $method,
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response' => $content,
                ]);
            }

            // If not successful, log error details and throw
            if ($statusCode >= 400) {
                $this->logger->error('Karla API returned error status', [
                    'component' => 'order.api',
                    'method' => $method,
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response_body' => $content,
                    'request_payload' => $orderData,
                ]);

                throw new \RuntimeException(
                    sprintf('Karla API returned %d error: %s', $statusCode, $content)
                );
            }
        } catch (\RuntimeException $e) {
            // Re-throw RuntimeException (HTTP error status codes we handled above)
            throw $e;
        } catch (\Throwable $e) {
            // Catch any other exception (network errors, timeouts, transport errors)
            $this->logger->error('Failed to send request to Karla API', [
                'component' => 'order.api',
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'request_payload' => $orderData,
            ]);

            // Re-throw to be caught by the outer exception handler in onOrderWritten
            throw $e;
        }
    }

    /**
     * Parse order line items
     * @param OrderLineItemCollection $lineItems
     * @return array
     */
    private function readLineItems(OrderLineItemCollection $lineItems)
    {
        $products = [];
        $discounts = [];
        $subTotalPrice = 0.0;
        $discountPrice = 0.0;

        foreach ($lineItems as $lineItem) {
            $payload = $lineItem->getPayload();
            if (in_array($lineItem->getType(), ['product', $this->depositLineItemType])) {
                $subTotalPrice += $lineItem->getTotalPrice();
                $product = $lineItem->getProduct();
                $cover = $product instanceof ProductEntity ? $product->getCover() : null;
                $media = $cover instanceof ProductMediaEntity ? $cover->getMedia() : null;

                // Determine product_id and variant_id
                // For Shopware: parent ID is the container, product ID is the variant
                $productId = $lineItem->getReferencedId(); // The actual product/variant ID

                // Try to get parent ID from payload first, then from product entity
                $parentId = null;
                if (is_array($payload) && isset($payload['parentId'])) {
                    $parentId = $payload['parentId'];
                } elseif ($product instanceof ProductEntity) {
                    $parentId = $product->getParentId();
                }

                $karlaProductId = $parentId ?? $productId; // Use parent if exists, else self
                $karlaVariantId = $productId; // The actual variant/product ID

                $products[] = [
                 'product_id' => $karlaProductId,
                 'variant_id' => $karlaVariantId,
                 'sku' => $product instanceof ProductEntity ? $product->getProductNumber() : $lineItem->getReferencedId(),
                 'title' => $lineItem->getLabel(),
                 'quantity' => $lineItem->getQuantity(),
                 'price' => $lineItem->getUnitPrice(),
                 'images' => $cover instanceof ProductMediaEntity ? [[
                  'src' => $media instanceof MediaEntity ? $media->getUrl() : null,
                  'alt' => $media instanceof MediaEntity ? $media->getAlt() : null,
                 ]] : [],
                ];
            } elseif ($lineItem->getType() === 'promotion') {
                $discountPrice += abs($lineItem->getTotalPrice());
                $discounts[] = [
                'code' => is_array($payload) ? $payload['code'] : "",
                'amount' => $lineItem->getTotalPrice(),
                'type' => is_array($payload) ? $payload['discountType'] : null,
                ];
            }
        }

        return [
        'products' => $products,
        'discounts' => $discounts,
        'subTotalPrice' => $subTotalPrice,
        'discountPrice' => $discountPrice,
        ];
    }

    /**
     * Parse products from a delivery position
     * @param OrderDeliveryPositionCollection $deliveryPositions
     * @return array
     */
    private function readDeliveryPositions(OrderDeliveryPositionCollection $deliveryPositions)
    {
        $products = [];

        foreach ($deliveryPositions as $deliveryPosition) {
            $lineItem = $deliveryPosition->getOrderLineItem();
            if ($lineItem->getType() === 'product') {
                $payload = $lineItem->getPayload();
                $product = $lineItem->getProduct();
                $cover = $product instanceof ProductEntity ? $product->getCover() : null;
                $media = $cover instanceof ProductMediaEntity ? $cover->getMedia() : null;

                // Determine product_id and variant_id
                // For Shopware: parent ID is the container, product ID is the variant
                $productId = $lineItem->getReferencedId(); // The actual product/variant ID

                // Try to get parent ID from payload first, then from product entity
                $parentId = null;
                if (is_array($payload) && isset($payload['parentId'])) {
                    $parentId = $payload['parentId'];
                } elseif ($product instanceof ProductEntity) {
                    $parentId = $product->getParentId();
                }

                $karlaProductId = $parentId ?? $productId; // Use parent if exists, else self
                $karlaVariantId = $productId; // The actual variant/product ID

                $products[] = [
                 'product_id' => $karlaProductId,
                 'variant_id' => $karlaVariantId,
                 'sku' => $product instanceof ProductEntity ? $product->getProductNumber() : $lineItem->getReferencedId(),
                 'title' => $lineItem->getLabel(),
                 'quantity' => $lineItem->getQuantity(),
                 'price' => $lineItem->getUnitPrice(),
                 'images' => $cover instanceof ProductMediaEntity ? [[
                  'src' => $media instanceof MediaEntity ? $media->getUrl() : null,
                  'alt' => $media instanceof MediaEntity ? $media->getAlt() : null,
                 ]] : [],
                ];
            }
        }

        return $products;
    }

    /**
     * Parse order address
     * @param OrderAddressEntity $address
     * @return array
     */
    private function readAddress(OrderAddressEntity $address): array
    {
        $country = $address->getCountry();
        $state = $address->getCountryState();

        $addressData = [
         'address_line_1' => $address->getStreet(),
         'address_line_2' => $address->getAdditionalAddressLine1(),
         'city' => $address->getCity(),
         'country' => $country instanceof CountryEntity ? $country->getName() : null,
         'country_code' => $country instanceof CountryEntity ? $country->getIso() : null,
         'name' => $address->getFirstName() . ' ' . $address->getLastName(),
         'phone' => $address->getPhoneNumber(),
         'province' => $state instanceof CountryStateEntity ? $state->getName() : null,
         'province_code' => $state instanceof CountryStateEntity ? $state->getShortCode() : null,
         'street' => trim(
             $address->getStreet() . ', ' . $address->getAdditionalAddressLine1(),
             ', '
         ),
         'zip_code' => $address->getZipcode(),
         ];

        return $addressData;
    }

    /**
     * Extract order tags as segments
     * @param OrderEntity $order
     * @return array
     */
    private function extractOrderTagsAsSegments(OrderEntity $order): array
    {
        $segments = [];

        // Add order tags
        $orderTags = $order->getTags();
        if ($orderTags !== null) {
            foreach ($orderTags as $tag) {
                $segments[] = "Shopware.tag." . $tag->getName();
            }
        }

        // Add customer tags (using same prefix as order tags)
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer && $orderCustomer->getCustomer()) {
            $customer = $orderCustomer->getCustomer();

            // Customer tags
            $customerTags = $customer->getTags();
            if ($customerTags !== null) {
                foreach ($customerTags as $tag) {
                    $segments[] = "Shopware.tag." . $tag->getName();
                }
            }

            // Customer group
            $customerGroup = $customer->getGroup();
            if ($customerGroup) {
                $segments[] = "Shopware.customer_group." . $customerGroup->getName();
            }
        }

        // Add sales channel
        $salesChannel = $order->getSalesChannel();
        if ($salesChannel) {
            $segments[] = "Shopware.sales_channel." . $salesChannel->getName();
        }

        if ($this->debugMode) {
            $this->logger->debug('Order segments determined', [
                'component' => 'order.sync',
                'order_number' => $order->getOrderNumber(),
                'segments_count' => count($segments),
                'segments' => $segments,
            ]);
        }

        return $segments;
    }

    /**
     * Parse sales channel mapping configuration
     * @param string $mappingConfig
     * @return array
     */
    private function parseSalesChannelMapping(string $mappingConfig): array
    {
        $mapping = [];

        if (empty($mappingConfig)) {
            return $mapping;
        }

        $pairs = explode(',', $mappingConfig);
        foreach ($pairs as $pair) {
            $parts = explode(':', trim($pair));
            if (count($parts) === 2) {
                $salesChannelId = trim($parts[0]);
                $shopSlug = trim($parts[1]);
                if (! empty($salesChannelId) && ! empty($shopSlug)) {
                    $mapping[$salesChannelId] = $shopSlug;
                }
            }
        }

        if ($this->debugMode) {
            $this->logger->debug('Sales channel mapping parsed', [
                'component' => 'order.config',
                'mapping' => $mapping,
            ]);
        }

        return $mapping;
    }

    /**
     * Get the shop slug for a specific sales channel
     * @param string|null $salesChannelId
     * @return string
     */
    private function getShopSlugForSalesChannel(?string $salesChannelId): string
    {
        if ($salesChannelId && isset($this->salesChannelMapping[$salesChannelId])) {
            $mappedSlug = $this->salesChannelMapping[$salesChannelId];
            if ($this->debugMode) {
                $this->logger->debug('Using mapped shop slug for sales channel', [
                    'component' => 'order.config',
                    'sales_channel_id' => $salesChannelId,
                    'shop_slug' => $mappedSlug,
                ]);
            }

            return $mappedSlug;
        }

        if ($this->debugMode) {
            $this->logger->debug('Using default shop slug for sales channel', [
                'component' => 'order.config',
                'sales_channel_id' => $salesChannelId ?? 'unknown',
                'shop_slug' => $this->shopSlug,
            ]);
        }

        return $this->shopSlug;
    }
}
