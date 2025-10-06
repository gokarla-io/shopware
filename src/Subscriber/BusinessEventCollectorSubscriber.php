<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Shopware\Core\Framework\Event\BusinessEventDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers Karla webhook events with Flow Builder.
 * This makes all Karla events available in the Flow Builder trigger dropdown.
 */
class BusinessEventCollectorSubscriber implements EventSubscriberInterface
{
    /**
     * @codeCoverageIgnore
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BusinessEventCollectorEvent::NAME => 'onCollectBusinessEvents',
        ];
    }

    /**
     * Register all Karla webhook events with Flow Builder.
     */
    public function onCollectBusinessEvents(BusinessEventCollectorEvent $event): void
    {
        $debugMode = (bool) $this->systemConfigService->get('KarlaDelivery.config.debugMode');
        $collection = $event->getCollection();
        $registeredCount = 0;
        $failedCount = 0;

        if ($debugMode) {
            $this->logger->debug('Starting Flow Builder event registration', [
                'component' => 'flow.builder',
                'total_events' => count(KarlaWebhookEvent::EVENT_GROUPS),
            ]);
        }

        foreach (KarlaWebhookEvent::EVENT_GROUPS as $eventGroup) {
            // Transform event_group to Shopware event name
            // Split on first underscore only: 'shipment_in_transit' -> 'shipment.in_transit'
            // Example: 'shipment_delivered' -> 'karla.shipment.delivered'
            $eventName = 'karla.' . implode('.', explode('_', $eventGroup, 2));

            try {
                // Create event definition manually
                // Get available data from the event class
                $availableData = KarlaWebhookEvent::getAvailableData()->toArray();

                // Build aware interfaces array (Shopware expects both short name and full interface)
                // This enables Flow Builder actions for mail, order, and customer operations
                $aware = [
                    'mailAware',
                    'Shopware\Core\Framework\Event\MailAware',
                    'orderAware',
                    'Shopware\Core\Framework\Event\OrderAware',
                    'customerAware',
                    'Shopware\Core\Framework\Event\CustomerAware',
                ];

                // Create the business event definition
                // Use the technical name (lowercase with dots/underscores) as the identifier
                // Example: 'karla.shipment.in_transit'
                $definition = new BusinessEventDefinition(
                    $eventName,
                    KarlaWebhookEvent::class,
                    $availableData,
                    $aware
                );

                $collection->set($eventName, $definition);
                $registeredCount++;

                if ($debugMode) {
                    $this->logger->debug('Registered Flow Builder event', [
                        'component' => 'flow.builder',
                        'event_group' => $eventGroup,
                        'event_name' => $eventName,
                    ]);
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $this->logger->error('Exception while registering Flow Builder event', [
                    'component' => 'flow.builder',
                    'event_group' => $eventGroup,
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($debugMode) {
            $this->logger->info('Flow Builder event registration completed', [
                'component' => 'flow.builder',
                'registered' => $registeredCount,
                'failed' => $failedCount,
                'total' => count(KarlaWebhookEvent::EVENT_GROUPS),
            ]);
        }
    }
}
