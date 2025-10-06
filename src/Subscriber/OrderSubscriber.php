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
            $this->logger->warning(
                'Missing critical configuration values: check shopSlug, apiUsername, apiKey, and/or apiUrl.'
            );
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
                $this->logger->warning('Critical configurations missing. Skipping order placement.');

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
            $this->logger->error(
                sprintf(
                    'Unexpected error: %s. File: %s, Line: %s',
                    $t->getMessage(),
                    $t->getFile(),
                    $t->getLine()
                )
            );
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
            $this->logger->info(
                sprintf(
                    'Order "%s" skipped: order status is "%s". Allowed order statuses are: %s',
                    $orderNumber,
                    $orderStatus,
                    json_encode($this->allowedOrderStatuses)
                )
            );

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
                $this->logger->info(
                    sprintf(
                        'Order "%s" delivery skipped: delivery status is "%s". ' .
                        'Allowed delivery statuses are: %s',
                        $orderNumber,
                        $deliveryStatus,
                        json_encode($this->allowedDeliveryStatuses)
                    )
                );

                continue;
            }
            $trackingCodes = $delivery->getTrackingCodes();
            // Supports only one tracking code per delivery
            $trackingNumber = $trackingCodes ? $trackingCodes[0] : null;
            if ($trackingNumber) {
                $this->logger->debug(
                    sprintf(
                        'Order "%s" delivery found: detected tracking number "%s".',
                        $trackingNumber,
                        $orderNumber,
                    )
                );
                $orderUpsertPayload['trackings'][] = [
                    'tracking_number' => $trackingNumber,
                    'tracking_placed_at' => (new \DateTime())->format(\DateTime::ATOM),
                    'products' => $this->readDeliveryPositions($delivery->getPositions()),
                ];
                $nDeliveries++;
            } else {
                $this->logger->info(
                    sprintf(
                        'Order "%s" delivery skipped: delivery has no tracking codes.',
                        $orderNumber,
                    )
                );
            }
        }

        $shopSlug = $this->getShopSlugForSalesChannel($order->getSalesChannelId());
        $url = $this->apiUrl . '/v1/shops/' . $shopSlug . '/orders';
        $this->sendRequestToKarlaApi($url, 'PUT', $orderUpsertPayload);

        if (! empty($segments)) {
            $this->logger->info(
                sprintf(
                    'Sent order "%s" data with %d segments and %d delivery/s to Karla.',
                    $orderNumber,
                    count($segments),
                    $nDeliveries
                )
            );
        } else {
            $this->logger->info(
                sprintf('Sent order "%s" data and %d delivery/s to Karla.', $orderNumber, $nDeliveries)
            );
        }
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

        $this->logger->debug(
            sprintf(
                'Preparing %s API request to %s with payload: %s.',
                $method,
                $url,
                json_encode($orderData),
            )
        );
        $response = $this->httpClient->request($method, $url, [
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->requestTimeout,
        ]);
        $content = $response->getContent();
        $statusCode = $response->getStatusCode();
        $this->logger->debug(
            sprintf(
                '%s API request to %s sent successfully. Status Code: %s. Response: %s.',
                $method,
                $url,
                $statusCode,
                $content,
            )
        );
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
                $products[] = [
                 'external_product_id' => $lineItem->getId(),
                 'sku' => $lineItem->getReferencedId(),
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
                $product = $lineItem->getProduct();
                $cover = $product instanceof ProductEntity ? $product->getCover() : null;
                $media = $cover instanceof ProductMediaEntity ? $cover->getMedia() : null;
                $products[] = [
                 'external_product_id' => $lineItem->getId(),
                 'sku' => $lineItem->getReferencedId(),
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

        // Add customer tags
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer && $orderCustomer->getCustomer()) {
            $customerTags = $orderCustomer->getCustomer()->getTags();
            if ($customerTags !== null) {
                foreach ($customerTags as $tag) {
                    $segments[] = "Shopware.customer.tag." . $tag->getName();
                }
            }
        }

        $this->logger->debug(
            sprintf(
                'Order "%s" has %d total segments: %s',
                $order->getOrderNumber(),
                count($segments),
                implode(', ', $segments)
            )
        );

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

        $this->logger->debug(
            sprintf(
                'Parsed sales channel mapping: %s',
                json_encode($mapping)
            )
        );

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
            $this->logger->debug(
                sprintf(
                    'Using mapped shop slug "%s" for sales channel "%s"',
                    $mappedSlug,
                    $salesChannelId
                )
            );

            return $mappedSlug;
        }

        $this->logger->debug(
            sprintf(
                'Using default shop slug "%s" for sales channel "%s"',
                $this->shopSlug,
                $salesChannelId ?? 'unknown'
            )
        );

        return $this->shopSlug;
    }
}
