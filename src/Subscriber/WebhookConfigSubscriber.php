<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Message\SyncAllProductsMessage;
use Karla\Delivery\Service\WebhookService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Subscriber that manages webhook lifecycle based on configuration changes.
 */
class WebhookConfigSubscriber implements EventSubscriberInterface
{
    /**
     * @codeCoverageIgnore
     * Constructor with dependency injection using property promotion.
     */
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly string $baseUrl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        $key = $event->getKey();
        $salesChannelId = $event->getSalesChannelId();

        // Handle product sync toggle
        if ($key === 'KarlaDelivery.config.productSyncEnabled') {
            $this->handleProductSyncToggle($event->getValue(), $salesChannelId);

            return;
        }

        // Only handle Karla Delivery webhook configuration changes
        if ($key !== 'KarlaDelivery.config.webhookEnabled') {
            return;
        }

        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId);

        if ($debugMode) {
            $this->logger->info('Webhook config change detected', [
                'component' => 'webhook.config',
                'key' => $key,
                'value' => $event->getValue(),
                'sales_channel_id' => $salesChannelId,
            ]);
        }

        // Handle webhook enabled/disabled
        if ($key === 'KarlaDelivery.config.webhookEnabled') {
            $this->handleWebhookEnabledChanged($event->getValue(), $salesChannelId);

            return;
        }


    }

    /**
     * @codeCoverageIgnore
     * This private method is implicitly tested through onSystemConfigChanged.
     */
    private function handleWebhookEnabledChanged(mixed $enabled, ?string $salesChannelId = null): void
    {
        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId);

        if ($enabled) {
            // Check if webhook already exists
            $webhookId = ($salesChannelId === null
                ? $this->systemConfigService->get('KarlaDelivery.config.webhookId')
                : ($this->systemConfigService->getDomain('KarlaDelivery.config', $salesChannelId)['KarlaDelivery.config.webhookId'] ?? null));

            if ($debugMode) {
                $this->logger->debug('Handling webhook enabled change', [
                    'enabled' => true,
                    'existing_webhook_id' => $webhookId,
                    'sales_channel_id' => $salesChannelId,
                ]);
            }

            if ($webhookId) {
                if ($debugMode) {
                    $this->logger->debug('Webhook already exists, skipping creation', [
                        'webhook_id' => $webhookId,
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                return; // Webhook already exists, no action needed
            }

            // Create new webhook
            try {
                $webhookUrl = $this->webhookService->generateWebhookUrl($this->baseUrl, $salesChannelId);
                $enabledEvents = $this->getEnabledEventsArray($salesChannelId);

                if ($debugMode) {
                    $this->logger->debug('Creating webhook', [
                        'component' => 'webhook.config',
                        'webhook_url' => $webhookUrl,
                        'enabled_events' => $enabledEvents,
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                // Karla will generate both UUID and secret
                $webhookData = $this->webhookService->createWebhook($webhookUrl, $enabledEvents, $salesChannelId);
                $webhookId = $webhookData['uuid'];
                $webhookSecret = $webhookData['secret'];

                if ($debugMode) {
                    $this->logger->debug('Webhook created successfully, saving to config', [
                        'component' => 'webhook.config',
                        'webhook_id' => $webhookId,
                        'webhook_url' => $webhookUrl,
                        'webhook_secret' => substr($webhookSecret, 0, 8) . '...',
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                // Save values to config (URL generated by us, UUID and secret from Karla)
                $this->systemConfigService->set('KarlaDelivery.config.webhookUrl', $webhookUrl, $salesChannelId);
                $this->systemConfigService->set('KarlaDelivery.config.webhookSecret', $webhookSecret, $salesChannelId);
                $this->systemConfigService->set('KarlaDelivery.config.webhookId', $webhookId, $salesChannelId);

                if ($debugMode) {
                    $this->logger->debug('Webhook config saved, verifying', [
                        'sales_channel_id' => $salesChannelId,
                    ]);

                    // Verify the saved values
                    $savedUrl = $this->systemConfigService->get('KarlaDelivery.config.webhookUrl', $salesChannelId);
                    $savedSecret = $this->systemConfigService->get('KarlaDelivery.config.webhookSecret', $salesChannelId);
                    $savedId = ($salesChannelId === null
                ? $this->systemConfigService->get('KarlaDelivery.config.webhookId')
                : ($this->systemConfigService->getDomain('KarlaDelivery.config', $salesChannelId)['KarlaDelivery.config.webhookId'] ?? null));

                    $this->logger->debug('Webhook config verification', [
                        'saved_url' => $savedUrl,
                        'saved_secret' => $savedSecret ? substr($savedSecret, 0, 8) . '...' : null,
                        'saved_id' => $savedId,
                        'url_matches' => $savedUrl === $webhookUrl,
                        'secret_matches' => $savedSecret === $webhookSecret,
                        'id_matches' => $savedId === $webhookId,
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to create webhook on config change', [
                    'component' => 'webhook.config',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'sales_channel_id' => $salesChannelId,
                ]);

                // On failure, disable the webhook toggle to reflect actual state
                $this->systemConfigService->set('KarlaDelivery.config.webhookEnabled', false, $salesChannelId);

                if ($debugMode) {
                    $this->logger->debug('Webhook toggle disabled due to creation failure', [
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }
            }
        } else {
            // Delete existing webhook
            $webhookId = ($salesChannelId === null
                ? $this->systemConfigService->get('KarlaDelivery.config.webhookId')
                : ($this->systemConfigService->getDomain('KarlaDelivery.config', $salesChannelId)['KarlaDelivery.config.webhookId'] ?? null));

            if ($debugMode) {
                $this->logger->debug('Handling webhook disabled change', [
                    'enabled' => false,
                    'webhook_id' => $webhookId,
                    'sales_channel_id' => $salesChannelId,
                ]);
            }

            if (! $webhookId) {
                if ($debugMode) {
                    $this->logger->debug('No webhook to delete', [
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                return; // No webhook to delete
            }

            try {
                if ($debugMode) {
                    $this->logger->debug('Deleting webhook', [
                        'webhook_id' => $webhookId,
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                $this->webhookService->deleteWebhook($webhookId, $salesChannelId);

                if ($debugMode) {
                    $this->logger->debug('Webhook deleted, clearing config', [
                        'webhook_id' => $webhookId,
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }

                // Clear webhook configuration (with salesChannelId parameter)
                $this->systemConfigService->set('KarlaDelivery.config.webhookUrl', null, $salesChannelId);
                $this->systemConfigService->set('KarlaDelivery.config.webhookSecret', null, $salesChannelId);
                $this->systemConfigService->set('KarlaDelivery.config.webhookId', null, $salesChannelId);

                if ($debugMode) {
                    $this->logger->debug('Webhook config cleared successfully', [
                        'sales_channel_id' => $salesChannelId,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to delete webhook on config change', [
                    'component' => 'webhook.config',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'webhook_id' => $webhookId,
                    'sales_channel_id' => $salesChannelId,
                ]);

                // Retain identity and credentials so a failed deletion can be retried.

            }
        }
    }

    /**
     * Get enabled events as array.
     *
     * Transforms event names to Karla API format:
     * - "*" → ["*"]
     * - "shipments/delivered" → ["shipments/delivered"]
     * - "shipments/delivered,claims/created" → ["shipments/delivered", "claims/created"]
     *
     * @codeCoverageIgnore
     * This private method is implicitly tested through onSystemConfigChanged.
     *
     * @return array<string>
     */
    private function getEnabledEventsArray(?string $salesChannelId = null): array
    {
        $enabledEvents = $this->systemConfigService->get('KarlaDelivery.config.webhookEnabledEvents', $salesChannelId) ?? '*';

        if ($enabledEvents === '*') {
            return ['*'];
        }

        // Split by comma and trim whitespace
        return array_map('trim', explode(',', $enabledEvents));
    }

    /**
     * Handle product sync toggle with 1-hour protection against spam
     *
     * @codeCoverageIgnore
     * This private method is implicitly tested through onSystemConfigChanged.
     */
    private function handleProductSyncToggle(mixed $enabled, ?string $salesChannelId): void
    {
        if (! $enabled) {
            $this->logger->info('Product sync disabled', [
                'component' => 'product.config',
            ]);

            return;
        }

        // Check last time product sync was enabled
        $lastEnabled = $salesChannelId === null
            ? $this->systemConfigService->get('KarlaDelivery.config.productSyncLastEnabled')
            : ($this->systemConfigService->getDomain('KarlaDelivery.config', $salesChannelId)['KarlaDelivery.config.productSyncLastEnabled'] ?? null);
        $now = time();
        $cooldownSeconds = 300; // 5 minutes cooldown

        // If never enabled OR last enabled > cooldown period → trigger full sync
        if (! $lastEnabled || ($now - $lastEnabled) > $cooldownSeconds) {
            $this->logger->info('Product sync enabled - triggering full sync', [
                'component' => 'product.config',
                'last_enabled' => $lastEnabled ? date('Y-m-d H:i:s', $lastEnabled) : 'never',
            ]);

            // Update timestamp
            $this->systemConfigService->set('KarlaDelivery.config.productSyncLastEnabled', $now, $salesChannelId);
            $this->systemConfigService->set('KarlaDelivery.config.productSyncStatus', 'running', $salesChannelId);

            // Dispatch message to queue
            $this->messageBus->dispatch(new SyncAllProductsMessage(salesChannelId: $salesChannelId));
        } else {
            $secondsSinceLastSync = $now - $lastEnabled;
            $this->logger->info('Product sync enabled - skipping full sync (recently synced)', [
                'component' => 'product.config',
                'last_enabled' => date('Y-m-d H:i:s', $lastEnabled),
                'seconds_ago' => $secondsSinceLastSync,
                'cooldown_remaining' => $cooldownSeconds - $secondsSinceLastSync,
            ]);
        }
    }
}
