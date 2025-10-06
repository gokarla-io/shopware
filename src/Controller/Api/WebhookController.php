<?php

declare(strict_types=1);

namespace Karla\Delivery\Controller\Api;

use Karla\Delivery\Event\KarlaWebhookEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for receiving webhook events from Karla API.
 */
class WebhookController extends AbstractController
{
    private const SIGNATURE_TOLERANCE = 300; // 5 minutes

    /**
     * @codeCoverageIgnore
     * Constructor with dependency injection using property promotion.
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle incoming webhook from Karla API.
     *
     * The {shopwareWebhookId} in the path is a unique identifier we generated.
     * It's not validated (we validate via HMAC signature instead), but provides
     * a unique endpoint URL for each webhook.
     *
     * Note: auth_required => false allows external services (Karla) to POST to this endpoint
     * without Shopware API authentication. Security is provided via HMAC-SHA256 signature verification.
     */
    #[Route(
        path: '/api/karla/webhooks/{shopwareWebhookId}',
        name: 'api.karla.webhook',
        defaults: ['_routeScope' => ['api'], 'auth_required' => false],
        methods: ['POST'],
    )]
    public function handleWebhook(Request $request, string $shopwareWebhookId, Context $context): JsonResponse
    {
        $debugMode = (bool) $this->systemConfigService->get('KarlaDelivery.config.debugMode');

        if ($debugMode) {
            $this->logger->debug('Webhook request received', [
                'component' => 'webhook.receiver',
                'shopware_webhook_id' => $shopwareWebhookId,
                'remote_ip' => $request->getClientIp(),
                'has_signature' => $request->headers->has('Karla-Signature'),
            ]);
        }

        // Check if webhook receiver is enabled
        $webhookEnabled = $this->systemConfigService->get('KarlaDelivery.config.webhookEnabled');
        if (! $webhookEnabled) {
            $this->logger->warning('Webhook receiver is disabled', [
                'component' => 'webhook.receiver',
            ]);

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Webhook receiver is disabled'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Get webhook secret
        $webhookSecret = $this->systemConfigService->get('KarlaDelivery.config.webhookSecret');

        // Verify signature
        $signature = $request->headers->get('Karla-Signature');
        if (! $signature) {
            $this->logger->warning('Webhook signature missing', [
                'component' => 'webhook.receiver',
            ]);

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid signature'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $payload = $request->getContent();

        if ($debugMode) {
            $this->logger->debug('Verifying webhook signature', [
                'component' => 'webhook.receiver',
                'payload_size' => strlen($payload),
            ]);
        }

        if (! $this->verifySignature($signature, $payload, $webhookSecret)) {
            $this->logger->warning('Invalid webhook signature', [
                'component' => 'webhook.receiver',
            ]);

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid signature'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($debugMode) {
            $this->logger->debug('Webhook signature verified successfully', [
                'component' => 'webhook.receiver',
            ]);
        }

        // Parse payload
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Invalid webhook payload', [
                'component' => 'webhook.receiver',
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid payload'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($debugMode) {
            $this->logger->debug('Webhook payload parsed successfully', [
                'component' => 'webhook.receiver',
                'event_group' => $data['event_group'] ?? null,
                'source' => $data['source'] ?? null,
                'ref' => $data['ref'] ?? null,
            ]);
        }

        // Create event and dispatch
        try {
            // Check if event_group is present
            $eventGroup = $data['event_group'] ?? null;

            if (! $eventGroup) {
                $this->logger->warning('event_group missing from webhook payload', [
                    'component' => 'webhook.receiver',
                    'source' => $data['source'] ?? null,
                ]);

                return new JsonResponse(
                    ['status' => 'error', 'message' => 'event_group is required'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            // Create and dispatch event (validation happens in getName())
            $event = new KarlaWebhookEvent($data, $context);
            $eventName = $event->getName(); // Technical name: karla.shipment.in_transit

            if ($debugMode) {
                $this->logger->debug('Dispatching webhook event', [
                    'component' => 'webhook.receiver',
                    'event_name' => $eventName,
                    'event_group' => $eventGroup,
                ]);
            }

            // Dispatch with technical name for Flow Builder matching
            $this->eventDispatcher->dispatch($event, $eventName);

            // Always log webhook trigger for metrics (structured, parseable)
            $this->logger->info('Webhook trigger received', [
                'component' => 'webhook.metrics',
                'event_name' => $eventName,
                'event_group' => $eventGroup,
                'webhook_ref' => $data['ref'] ?? null,
                'webhook_source' => $data['source'] ?? null,
                'order_id' => $data['context']['order']['external_id'] ?? null,
                'customer_id' => $data['context']['customer']['external_id'] ?? null,
                'triggered_at' => $data['triggered_at'] ?? null,
                'event_data_keys' => array_keys($data['event_data'] ?? []),
            ]);

            if ($debugMode) {
                // Get mail struct to debug email recipient
                $mailStruct = $event->getMailStruct();
                $recipients = $mailStruct->getRecipients();

                $this->logger->debug('Webhook event dispatched successfully', [
                    'component' => 'webhook.receiver',
                    'event_name' => $eventName,
                    'event_group' => $eventGroup,
                    'available_data' => array_keys($event->getValues()),
                    'mail_recipients' => array_keys($recipients), // Show recipient emails
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process webhook', [
                'component' => 'webhook.receiver',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Failed to process webhook'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        if ($debugMode) {
            $this->logger->info('Webhook processed successfully', [
                'component' => 'webhook.receiver',
                'event_group' => $data['event_group'],
            ]);
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    /**
     * Verify webhook signature using HMAC-SHA256.
     *
     * @codeCoverageIgnore
     * This private method is implicitly tested through the handleWebhook method.
     */
    private function verifySignature(string $signatureHeader, string $payload, string $secret): bool
    {
        // Parse signature header: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) === 2) {
                $parts[$keyValue[0]] = $keyValue[1];
            }
        }

        if (! isset($parts['t']) || ! isset($parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $expectedSignature = $parts['v1'];

        // Check timestamp tolerance (prevent replay attacks)
        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            $this->logger->warning('Webhook timestamp too old', [
                'component' => 'webhook.receiver',
                'timestamp' => $timestamp,
                'current_time' => time(),
                'age_seconds' => abs(time() - $timestamp),
            ]);

            return false;
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $computedSignature = hash_hmac('sha256', $signedPayload, $secret);

        // Use timing-safe comparison
        return hash_equals($computedSignature, $expectedSignature);
    }
}
