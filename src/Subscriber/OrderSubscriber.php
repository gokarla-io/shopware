<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use DateTimeInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;

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

        // Log warnings if configuration values are missing
        if (empty($this->shopSlug) || empty($this->apiKey) || empty($this->apiUrl)) {
            $this->logger->warning(
                '[Karla] Missing critical configuration values: check shopSlug, apiUsername, apiKey, and/or apiUrl.'
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
                $this->logger->warning('[Karla] Critical configurations missing. Skipping order placement.');
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
                'stateMachineState',
                'transactions.stateMachineState',
            ]);

            $orders = $this->orderRepository->search($criteria, $context);
            foreach ($orders as $order) {
                $deliveries = $order->getDeliveries();
                $this->sendKarlaOrder($order, $deliveries);
            }
        } catch (\Throwable $t) {
            $this->logger->error(
                sprintf(
                    '[Karla] Unexpected error: %s. File: %s, Line: %s',
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
    private function sendKarlaOrder(OrderEntity $order, OrderDeliveryCollection $deliveries): void
    {
        $orderNumber = $order->getOrderNumber();
        $orderStatus = $order->getStateMachineState()->getTechnicalName();

        if (!in_array($orderStatus, $this->allowedOrderStatuses, true)) {
            $this->logger->info(
                sprintf(
                    '[Karla] Order "%s" skipped: order status is "%s". Allowed order statuses are: %s',
                    $orderNumber,
                    $orderStatus,
                    json_encode($this->allowedOrderStatuses)
                )
            );
            return;
        }

        $customer = $order->getOrderCustomer();
        $customerEmail = $customer ? $customer->getEmail() : null;

        $currency = $order->getCurrency();
        $currencyCode = $currency ? $currency->getIsoCode() : null;

        $lineItemDetails = $this->readLineItems($order->getLineItems());

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
            ],
         'trackings' => [],
        ];
        $nDeliveries = 0;
        foreach ($deliveries as $delivery) {
            $deliveryStatus = $delivery->getStateMachineState()->getTechnicalName();
            if (!in_array($deliveryStatus, $this->allowedDeliveryStatuses, true)) {
                $this->logger->info(
                    sprintf(
                        '[Karla] Order "%s" delivery skipped: delivery status is "%s". ' .
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
                        '[Karla] Order "%s" delivery found: detected tracking number "%s".',
                        $trackingNumber,
                        $orderNumber,
                    )
                );
                $orderUpsertPayload['trackings'][] = [
                    'tracking_number' => $trackingNumber,
                    'tracking_placed_at' => (new \DateTime())->format(\DateTime::ATOM),
                    'products' => $this->readDeliveryPositions($delivery->getPositions())
                ];
                $nDeliveries++;
            } else {
                $this->logger->info(
                    sprintf(
                        '[Karla] Order "%s" delivery skipped: delivery has no tracking codes.',
                        $orderNumber,
                    )
                );
            }
        }

        $url = $this->apiUrl . '/v1/shops/' . $this->shopSlug . '/orders';
        $this->sendRequestToKarlaApi($url, 'PUT', $orderUpsertPayload);
        $this->logger->info(
            sprintf('[Karla] Sent order "%s" data and %d delivery/s to Karla.', $orderNumber, $nDeliveries)
        );
    }

    /**
     * Send request to Karla's API
     *
     * @param string $url
     * @param string $method
     * @param array $orderData
     * @param LoggerInterface $logger
     * @param HttpClientInterface $httpClient
     */
    private function sendRequestToKarlaApi(string $url, string $method, array $orderData): void
    {
        $jsonPayload = json_encode($orderData);
        $auth = base64_encode($this->apiUsername . ':' . $this->apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        $response = $this->httpClient->request($method, $url, [
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->requestTimeout,
        ]);
        $content = $response->getContent();
        $statusCode = $response->getStatusCode();
         $this->logger->debug(
             sprintf(
                 '[Karla] %s API request sent successfully. Status Code: %s. Response: %s. Payload: %s.',
                 $method,
                 $statusCode,
                 $content,
                 json_encode($orderData),
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
                 'title' => $lineItem->getLabel(),
                 'quantity' => $lineItem->getQuantity(),
                 'price' => $lineItem->getUnitPrice(),
                 'images' => $cover instanceof ProductMediaEntity ? [[
                  'src' => $media instanceof MediaEntity ? $media->getUrl() : null,
                  'alt' => $media instanceof MediaEntity ? $media->getAlt() : null
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
                 'title' => $lineItem->getLabel(),
                 'quantity' => $lineItem->getQuantity(),
                 'price' => $lineItem->getUnitPrice(),
                 'images' => $cover instanceof ProductMediaEntity ? [[
                  'src' => $media instanceof MediaEntity ? $media->getUrl() : null,
                  'alt' => $media instanceof MediaEntity ? $media->getAlt() : null
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
}
