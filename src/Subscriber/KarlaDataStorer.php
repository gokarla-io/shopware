<?php

declare(strict_types=1);

namespace Karla\Delivery\Subscriber;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Framework\Event\FlowEventAware;

/**
 * Stores Karla webhook data so it's available in Flow Builder email templates.
 *
 * This storer extracts data from KarlaWebhookEvent and makes it available
 * as template variables in Flow Builder actions (like Send Email).
 */
class KarlaDataStorer extends FlowStorer
{
    /**
     * Storage key for Karla data in flow storage.
     */
    public const KARLA_DATA = 'karla';

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Store Karla webhook data in the flow storage.
     *
     * @param array<string, mixed> $stored
     * @param FlowEventAware $event
     * @return array<string, mixed>
     */
    public function store($event, array $stored): array
    {
        if (! $event instanceof KarlaWebhookEvent) {
            return $stored;
        }

        // Get the Karla data from the event
        $values = $event->getValues();

        // Store under 'karla' key for template access
        if (isset($values['karla'])) {
            $karlaData = $values['karla'];
            $stored[self::KARLA_DATA] = $karlaData;

            // Debug: Log the exact data being stored for template use
            $this->logger->debug('Storing Karla data for Flow Builder templates', [
                'component' => 'flow.storer',
                'available_keys' => is_array($karlaData) ? array_keys($karlaData) : [],
                'data' => $karlaData,
            ]);
        }

        return $stored;
    }

    /**
     * Restore Karla data from flow storage.
     *
     * @param StorableFlow $storable
     * @return void
     */
    public function restore(StorableFlow $storable): void
    {
        if (! $storable->hasStore(self::KARLA_DATA)) {
            return;
        }

        $storable->setData(self::KARLA_DATA, $storable->getStore(self::KARLA_DATA));
    }
}
