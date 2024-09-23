<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use DateTimeInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
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
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
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
     * @var bool
     */
    private bool $sendOrderPlacements;

    /**
     * @var bool
     */
    private bool $sendOrderFulfillments;

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

        // Plugin Configuration
        $this->shopSlug = $systemConfigService->get('KarlaDelivery.config.shopSlug') ?? '';
        $this->apiUsername = $systemConfigService->get('KarlaDelivery.config.apiUsername') ?? '';
        $this->apiKey = $systemConfigService->get('KarlaDelivery.config.apiKey') ?? '';
        $this->apiUrl = $systemConfigService->get('KarlaDelivery.config.apiUrl') ?? '';
        $this->sendOrderPlacements = $systemConfigService->get(
            'KarlaDelivery.config.sendOrderPlacements'
        ) ?? false;
        $this->sendOrderFulfillments = $systemConfigService->get(
            'KarlaDelivery.config.sendOrderFulfillments'
        ) ?? false;
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
            $source = $context->getSource();
            $orderIds = $event->getIds();

            $criteria = new Criteria($orderIds);
            $criteria->addAssociations(
                ['stateMachineState', 'orderCustomer', 'currency']
            );
            $criteria->addAssociations(
                ['addresses.country', 'addresses.countryState']
            );
            $criteria->addAssociations(
                ['lineItems.product', 'lineItems.product.cover', 'lineItems.product.cover.media']
            );

            if ($source instanceof AdminApiSource) {
                if (!$this->sendOrderFulfillments) {
                    $this->logger->info('[Karla] Order fulfillment is disabled.');
                }
                // Event triggered from the admin panel
                $criteria->addAssociations([
                   'deliveries.stateMachineState',
                   'deliveries.trackingCodes',
                   'deliveries.positions',
                   'deliveries.positions.orderLineItem.product',
                ]);

                $orders = $this->orderRepository->search($criteria, $context);

                foreach ($orders as $order) {
                    $deliveries = $order->getDeliveries();
                    $this->logger->debug(
                        sprintf(
                            '[Karla] Processing order %s from admin panel. Deliveries: %s',
                            $order->getOrderNumber(),
                            json_encode($deliveries)
                        ),
                    );
                    if (!empty($deliveries)) {
                        $this->fulfillKarlaOrder($order, $deliveries);
                    }
                }
            } else {
                // Event triggered from the storefront
                if (!$this->sendOrderPlacements) {
                    $this->logger->info('[Karla] Order placement is disabled.');
                    return;
                }

                $orders = $this->orderRepository->search($criteria, $context);

                foreach ($orders as $order) {
                    $this->logger->debug(
                        sprintf(
                            '[Karla] Processing order %s from storefront. Data: ',
                            $order->getOrderNumber(),
                            json_encode($order)
                        )
                    );
                    $this->placeKarlaOrder($order);
                }
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
     * Place an order through Karla's API
     * @param OrderEntity $order
     */
    private function placeKarlaOrder(OrderEntity $order): void
    {
        $orderNumber = $order->getOrderNumber();
        $customer = $order->getOrderCustomer();
        $customerEmail = $customer ? $customer->getEmail() : null;

        $currency = $order->getCurrency();
        $currencyCode = $currency ? $currency->getIsoCode() : null;

        $lineItemDetails = $this->readLineItems($order->getLineItems());

        $orderPlacementPayload = [
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
        ];

        $url = $this->apiUrl . '/v1/shops/' . $this->shopSlug . '/orders';
        $this->sendRequestToKarlaApi($url, 'POST', $orderPlacementPayload);
        $this->logger->info(
            sprintf('[Karla] Sent order placement to Karla for order number %s.', $orderNumber)
        );
    }

    /**
     * Fulfill an order through Karla's API
     * @param OrderEntity $order
     * @param OrderDeliveryCollection $deliveries Array of OrderDeliveryEntity objects
     */
    private function fulfillKarlaOrder(OrderEntity $order, OrderDeliveryCollection $deliveries): void
    {
        $orderNumber = $order->getOrderNumber();
        $orderFulfillmentPayload = [
         'id' => $orderNumber,
         'id_type' => 'order_number',
         'trackings' => [],
        ];
        foreach ($deliveries as $delivery) {
            $trackingCodes = $delivery->getTrackingCodes();
            // Supports only one tracking code per delivery
            $trackingNumber = $trackingCodes ? $trackingCodes[0] : null;
            if ($trackingNumber) {
                // Add the tracking information to the payload
                $shippingMethod = $delivery->getShippingMethod();
                $carrierReference = $shippingMethod instanceof ShippingMethodEntity ? $shippingMethod->getName() : null;

                $orderFulfillmentPayload['trackings'][] = [
                    'tracking_number' => $trackingNumber,
                    'tracking_placed_at' => (new \DateTime())->format(\DateTime::ATOM),
                    'carrier_reference' => $carrierReference,
                    'products' => $this->readDeliveryPositions($delivery->getPositions())
                ];
            }
        }

        if (empty($orderFulfillmentPayload['trackings'])) {
            $this->logger->warning(
                sprintf(
                    '[Karla] No tracking information found for order %s. Skipping order fulfillment. Payload: %s',
                    $order->getOrderNumber(),
                    json_encode($orderFulfillmentPayload),
                )
            );
            return;
        }

        $url = $this->apiUrl . '/v1/shops/' . $this->shopSlug . '/orders';
        $this->sendRequestToKarlaApi($url, 'PUT', $orderFulfillmentPayload);
        $this->logger->info(
            sprintf('[Karla] Sent order fulfillment to Karla for order number %s.', $orderNumber)
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
        $auth = base64_encode($this->apiUsername . ':' . $this->apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        $jsonPayload = json_encode($orderData);

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $jsonPayload,
            ]);

            $content = $response->getContent();
            $this->logger->debug(
                sprintf('[Karla] API request (%s) sent successfully. Response: %s', $method, $content)
            );
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    '[Karla] Failed to send API request (%s). Status Code: %s. Response: %s. Error: %s.',
                    $method,
                    $e->getMessage(),
                    $response->getStatusCode(),
                    $response->getContent()
                )
            );
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
