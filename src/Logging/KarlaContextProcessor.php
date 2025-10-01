<?php

declare(strict_types=1);

namespace Karla\Delivery\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds consistent Karla namespace context to all log records.
 *
 * This processor automatically adds 'namespace' and 'app' to the extra
 * context of all log records in the karla_delivery channel, making it
 * easy to filter logs in Pimp My Log and other log aggregation tools.
 *
 * The processor checks if a 'component' key exists in the log context
 * and uses it to create a hierarchical namespace (e.g., 'karla.webhook.api').
 * If no component is specified, it defaults to 'karla'.
 */
class KarlaContextProcessor implements ProcessorInterface
{
    /**
     * Adds Karla namespace context to log records.
     *
     * @param LogRecord $record The log record to process
     *
     * @return LogRecord The processed log record with added context
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Check if component is specified in context for hierarchical namespace
        $component = $record->context['component'] ?? null;

        // Build hierarchical namespace: karla.webhook.api, karla.order, etc.
        if ($component) {
            $record->extra['namespace'] = 'karla.' . $component;
        } else {
            $record->extra['namespace'] = 'karla';
        }

        // Always add app identifier
        $record->extra['app'] = 'karla_delivery';

        return $record;
    }
}
