<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CatalogApiClient
{
    public function __construct(private HttpClientInterface $httpClient, private SystemConfigService $systemConfigService, private LoggerInterface $logger)
    {
    }

    public function request(string $url, string $method, array $data, ?string $salesChannelId, bool $allowMissingVariant = false): ResponseInterface
    {
        $apiUsername = $this->systemConfigService->get('KarlaDelivery.config.apiUsername', $salesChannelId);
        $apiKey = $this->systemConfigService->get('KarlaDelivery.config.apiKey', $salesChannelId);
        $requestTimeout = $this->systemConfigService->get('KarlaDelivery.config.requestTimeout', $salesChannelId) ?? 10.0;
        $debugMode = $this->systemConfigService->get('KarlaDelivery.config.debugMode', $salesChannelId) ?? false;

        // Validate required config
        if (empty($apiUsername) || empty($apiKey)) {
            throw new \RuntimeException('Missing API credentials (apiUsername or apiKey)');
        }

        $auth = base64_encode($apiUsername . ':' . $apiKey);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
        ];

        $requestOptions = [
            'headers' => $headers,
            'timeout' => $requestTimeout,
        ];

        // Only add body for PUT/POST
        if (in_array($method, ['PUT', 'POST'])) {
            $requestOptions['body'] = json_encode($data);
        }

        if ($debugMode) {
            $this->logger->debug('Preparing API request to Karla', [
                'component' => 'product.api',
                'method' => $method,
                'url' => $url,
                'payload' => $data,
            ]);
        }

        $response = $this->httpClient->request($method, $url, $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400 && ! ($allowMissingVariant && $method === 'DELETE' && $statusCode === 404)) {
            throw new \RuntimeException('Karla catalog API returned HTTP ' . $statusCode);
        }

        if ($debugMode) {
            $content = $response->getContent(false); // false = don't throw on error status
            $this->logger->debug('API request to Karla completed', [
                'component' => 'product.api',
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
                'response' => $content,
            ]);
        }

        return $response;
    }
}
