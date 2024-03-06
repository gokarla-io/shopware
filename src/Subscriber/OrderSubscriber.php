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
    private string $merchantSlug;

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
        $this->merchantSlug = $systemConfigService->get('KarlaDelivery.config.merchantSlug');
        $this->apiKey = $systemConfigService->get('KarlaDelivery.config.apiKey');
        $this->apiUrl = $systemConfigService->get('KarlaDelivery.config.apiUrl');
        $this->sendOrderPlacements = $systemConfigService->get('KarlaDelivery.config.sendOrderPlacements');
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    /**
     * @param EntityWrittenEvent $event
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        try {
            if ($this->sendOrderPlacements) {
                $context = $event->getContext();
                $source = $context->getSource();
                $orderIds = $event->getIds();

                if ($source instanceof AdminApiSource) {
                    $this->logger->info(
                        sprintf(
                            '[Karla] Order Event ids %s detected when triggered from the admin interface. Skipping...',
                            json_encode($orderIds),
                            $source
                        )
                    );
                    return;
                }

                $this->logger->info(
                    sprintf(
                        '[Karla] Order Event ids %s detected (source %s). Processing...',
                        json_encode($orderIds),
                        json_encode($source->jsonSerialize())
                    )
                );

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

                $orders = $this->orderRepository->search($criteria, $context);

                foreach ($orders as $order) {
                    $this->placeKarlaOrder($order);
                }
            } else {
                $this->logger->info(
                    sprintf(
                        '[Karla] Order Event detected: %s (Order placement disabled)',
                        json_encode($event->getIds())
                    )
                );
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
        $this->logger->info(
            sprintf(
                '[Karla] Order Event ids %s sent successfully',
                json_encode($orderIds),
            )
        );
    }

    /**
     * @param OrderEntity $order
     */
    private function placeKarlaOrder(OrderEntity $order): void
    {
        $orderData = $this->readOrder($order);
        $url = $this->apiUrl . '/v1/orders';

        $auth = base64_encode($this->merchantSlug . ':' . $this->apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        $jsonPayload = json_encode($orderData);
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $jsonPayload,
            ]);

            $content = $response->getContent();
            $this->logger->info(
                sprintf('[Karla] Order sent successfully. Response: %s', $content)
            );
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    '[Karla] Failed to send order. Status Code: %s. Response: %s. Error: %s.',
                    $e->getMessage(),
                    $response->getStatusCode(),
                    $response->getContent()
                )
            );
        }
    }


    /**
     * @param OrderEntity $order
     * @return array
     */
    private function readOrder(OrderEntity $order)
    {
        $customer = $order->getOrderCustomer();
        $customerEmail = $customer ? $customer->getEmail() : null;

        $currency = $order->getCurrency();
        $currencyCode = $currency ? $currency->getIsoCode() : null;

        $lineItemDetails = $this->readLineItems($order->getLineItems());

        $orderData = [
            'order_number' => $order->getOrderNumber(),
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

        $this->logger->info(sprintf('[Karla] Detected order: %s', json_encode($orderData)));
        return $orderData;
    }

    /**
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
            if ($lineItem->getType() === 'product') {
                $subTotalPrice += $lineItem->getTotalPrice();
                $product = $lineItem->getProduct();
                $cover = $product instanceof ProductEntity ? $lineItem->getProduct()->getCover() : null;
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
