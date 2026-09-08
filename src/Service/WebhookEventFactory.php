<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

class WebhookEventFactory
{
    /** @param EntityRepository<OrderCollection> $orderRepository */
    public function __construct(private readonly EntityRepository $orderRepository)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, Context $context): KarlaWebhookEvent
    {
        $orderId = (new KarlaWebhookEvent($data, $context))->getOrderId();
        if (! Uuid::isValid($orderId)) {
            throw new \RuntimeException('Invalid Shopware order ID in webhook context.');
        }

        $criteria = (new Criteria([$orderId]))->addAssociation('language');
        $order = $this->orderRepository->search($criteria, $context)->first();
        if (! $order instanceof OrderEntity) {
            throw new \RuntimeException('Shopware order not found for webhook.');
        }

        // SendMailAction loads translated templates using the flow context, before
        // it reads LanguageAware data. Keep both aligned with the original order.
        $flowContext = clone $context;
        $flowContext->assign(['languageIdChain' => array_values(array_unique(array_filter([
            $order->getLanguageId(),
            $order->getLanguage()?->getParentId(),
            Defaults::LANGUAGE_SYSTEM,
        ])))]);

        return new KarlaWebhookEvent($data, $flowContext, $order->getSalesChannelId());
    }
}
