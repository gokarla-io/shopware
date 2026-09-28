<?php

declare(strict_types=1);

namespace Karla\Delivery\MessageHandler;

use Karla\Delivery\Message\SyncAllProductsMessage;
use Karla\Delivery\Service\ProductSyncService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class SyncAllProductsMessageHandler
{
    private ProductSyncService $productSyncService;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;
    private SystemConfigService $systemConfigService;

    public function __construct(
        ProductSyncService $productSyncService,
        LoggerInterface $logger,
        MessageBusInterface $messageBus,
        SystemConfigService $systemConfigService
    ) {
        $this->productSyncService = $productSyncService;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
        $this->systemConfigService = $systemConfigService;
    }

    public function __invoke(SyncAllProductsMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();

        try {
            if ($salesChannelId === null && ($channels = $this->productSyncService->getSalesChannelIds()) !== null) {
                foreach ($channels as $channel) {
                    if ($this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled', $channel)) {
                        $this->messageBus->dispatch(new SyncAllProductsMessage(0, $message->getLimit(), $channel));
                    }
                }

                return;
            }
            if (! $this->systemConfigService->get('KarlaDelivery.config.productSyncEnabled', $salesChannelId)) {
                return;
            }

            $this->logger->info('Processing product bulk sync batch', [
                'component' => 'product.bulk_sync',
                'offset' => $message->getOffset(),
                'limit' => $message->getLimit(),
            ]);

            $hasMore = $this->productSyncService->syncProductBatch(
                $message->getOffset(),
                $message->getLimit(),
                $salesChannelId
            );

            // If there are more products, dispatch another message for the next batch
            if ($hasMore) {
                $nextMessage = new SyncAllProductsMessage(
                    $message->getOffset() + $message->getLimit(),
                    $message->getLimit(),
                    $salesChannelId
                );
                $this->messageBus->dispatch($nextMessage);

                $this->logger->info('Dispatched next product sync batch', [
                    'component' => 'product.bulk_sync',
                    'next_offset' => $nextMessage->getOffset(),
                ]);
            } else {
                // All done!
                $this->systemConfigService->set('KarlaDelivery.config.productSyncStatus', 'completed', $salesChannelId);

                $this->logger->info('Product bulk sync completed', [
                    'component' => 'product.bulk_sync',
                    'total_processed' => $message->getOffset() + $message->getLimit(),
                ]);
            }
        } catch (\Throwable $t) {
            $this->systemConfigService->set('KarlaDelivery.config.productSyncStatus', 'failed', $salesChannelId);

            $this->logger->error('Error during product bulk sync batch', [
                'component' => 'product.bulk_sync',
                'error' => $t->getMessage(),
                'file' => $t->getFile(),
                'line' => $t->getLine(),
                'trace' => $t->getTraceAsString(),
                'offset' => $message->getOffset(),
            ]);
        }
    }
}
