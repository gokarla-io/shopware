<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\CatalogApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CatalogApiClientTest extends TestCase
{
    public static function requests(): array
    {
        return ['read' => ['GET', 200, false, false], 'create debug' => ['POST', 201, true, false], 'update' => ['PUT', 200, false, false], 'idempotent delete' => ['DELETE', 404, false, false], 'server failure' => ['GET', 503, false, true]];
    }

    #[DataProvider('requests')]
    public function testRequestsAuthenticateAndMakeFailuresRetryable(string $method, int $status, bool $debug, bool $fails): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn ($key) => match ($key) {
            'KarlaDelivery.config.apiUsername' => 'user', 'KarlaDelivery.config.apiKey' => 'key', 'KarlaDelivery.config.requestTimeout' => 3.0, 'KarlaDelivery.config.debugMode' => $debug, default => null
        });
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->willReturn('{}');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(static function ($actualMethod, $url, $options) use ($method, $response) {
            self::assertSame($method, $actualMethod);
            self::assertSame('Basic ' . base64_encode('user:key'), $options['headers']['Authorization']);
            self::assertSame(3.0, $options['timeout']);
            self::assertSame(in_array($method, ['POST', 'PUT'], true), isset($options['body']));

            return $response;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($debug ? self::exactly(2) : self::never())->method('debug');
        if ($fails) {
            $this->expectExceptionMessage('Karla catalog API returned HTTP 503');
        }
        $result = (new CatalogApiClient($http, $config, $logger))->request('https://api.example/products', $method, ['title' => 'Product'], 'a');
        self::assertSame($response, $result);
    }

    public function testMissingCredentialsCannotSendRequests(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $this->expectExceptionMessage('Missing API credentials');
        (new CatalogApiClient($http, $this->createMock(SystemConfigService::class), $this->createMock(LoggerInterface::class)))->request('https://api.example/products', 'GET', [], null);
    }
}
