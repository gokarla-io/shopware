<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Controller\Api\WebhookController;
use Karla\Delivery\Event\KarlaWebhookEvent;
use Karla\Delivery\Service\WebhookEventFactory;
use Karla\Delivery\Service\WebhookService;
use Karla\Delivery\Subscriber\WebhookConfigSubscriber;
use Karla\Delivery\Tests\Fixtures\KarlaWebhookPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SalesChannelWebhookTest extends TestCase
{
    private const CHANNEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function callbacks(): array
    {
        return [
            'prefixed deployment' => [true, 'channel-secret', self::CHANNEL, 'callback', 200, '/shop'],
            'prefixed wrong callback' => [true, 'channel-secret', self::CHANNEL, 'wrong', 404, '/shop'],
            'own channel' => [true, 'channel-secret', self::CHANNEL, 'callback', 200],
            'wrong secret' => [true, 'global-secret', self::CHANNEL, 'callback', 401],
            'another channel order' => [true, 'channel-secret', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'callback', 403],
            'disabled channel' => [false, 'channel-secret', self::CHANNEL, 'callback', 403],
            'unknown callback' => [true, 'channel-secret', self::CHANNEL, 'unknown', 404],
        ];
    }

    #[DataProvider('callbacks')]
    public function testCallbackIsolation(bool $enabled, string $secret, string $orderChannel, string $id, int $status, string $basePath = ''): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getDomain')->with('KarlaDelivery.config', self::CHANNEL, false)->willReturn([
            'KarlaDelivery.config.webhookEnabled' => $enabled,
            'KarlaDelivery.config.webhookSecret' => 'channel-secret',
            'KarlaDelivery.config.webhookUrl' => 'https://shop.example' . $basePath . '/api/karla/webhooks/' . self::CHANNEL . '/callback',
        ]);
        $factory = $this->createMock(WebhookEventFactory::class);
        $factory->method('create')->willReturnCallback(static fn (array $data, Context $context) => new KarlaWebhookEvent($data, $context, $orderChannel));
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($status === 200 ? self::once() : self::never())->method('dispatch');
        $controller = new WebhookController($config, $dispatcher, new NullLogger(), $factory);
        $payload = KarlaWebhookPayloads::shipmentJson();
        $time = time();
        $request = new Request(content: $payload);
        $request->headers->set('Karla-Signature', 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret));

        self::assertSame($status, $controller->handleWebhook($request, $id, Context::createDefaultContext(), self::CHANNEL)->getStatusCode());
    }

    public function testChannelSubscriptionUsesMappedShopAndOwnCredentials(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['KarlaDelivery.config.shopSlug', self::CHANNEL, 'global-shop'],
            ['KarlaDelivery.config.salesChannelMapping', null, self::CHANNEL . ':new-brand'],
            ['KarlaDelivery.config.apiUsername', self::CHANNEL, 'brand-user'],
            ['KarlaDelivery.config.apiKey', self::CHANNEL, 'brand-key'],
            ['KarlaDelivery.config.apiUrl', self::CHANNEL, 'https://api.example'],
            ['KarlaDelivery.config.debugMode', self::CHANNEL, true],
        ]);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn(['uuid' => 'subscription', 'secret' => 'brand-secret']);
        $client = $this->createMock(HttpClientInterface::class);
        $methods = [];
        $client->expects(self::exactly(3))->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options) use ($response, &$methods) {
                $methods[] = $method;
                self::assertSame('https://api.example/v1/shops/new-brand/webhooks' . ($method === 'POST' ? '' : '/subscription'), $url);
                self::assertSame(['brand-user', 'brand-key'], $options['auth_basic']);

                return $response;
            },
        );
        $service = new WebhookService($config, $client, new NullLogger());
        $url = $service->generateWebhookUrl('https://shop.example', self::CHANNEL);
        self::assertStringStartsWith('https://shop.example/api/karla/webhooks/' . self::CHANNEL . '/', $url);
        self::assertSame(['uuid' => 'subscription', 'secret' => 'brand-secret'], $service->createWebhook($url, ['*'], self::CHANNEL));
        $service->updateWebhook('subscription', $url, ['*'], self::CHANNEL);
        $service->deleteWebhook('subscription', self::CHANNEL);
        self::assertSame(['POST', 'PATCH', 'DELETE'], $methods);
    }

    public function testEnablingChannelDoesNotReuseGlobalSubscription(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['KarlaDelivery.config.webhookId', self::CHANNEL, 'inherited-global-id'],
        ]);
        $config->method('getDomain')->with('KarlaDelivery.config', self::CHANNEL, false)->willReturn([]);
        $service = $this->createMock(WebhookService::class);
        $service->expects(self::once())->method('generateWebhookUrl')->with('https://shop.example', self::CHANNEL)->willReturn('https://shop.example/callback');
        $service->expects(self::once())->method('createWebhook')->with('https://shop.example/callback', ['*'], self::CHANNEL)->willReturn(['uuid' => 'own-id', 'secret' => 'own-secret']);
        $config->expects(self::exactly(3))->method('set')->with(self::anything(), self::anything(), self::CHANNEL);
        $subscriber = new WebhookConfigSubscriber($service, $config, new NullLogger(), $this->createMock(MessageBusInterface::class), 'https://shop.example');
        $subscriber->onSystemConfigChanged(new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', true, self::CHANNEL));
    }

    public function testDisablingChannelDoesNotDeleteGlobalSubscription(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('inherited-global-id');
        $config->method('getDomain')->willReturn([]);
        $service = $this->createMock(WebhookService::class);
        $service->expects(self::never())->method('deleteWebhook');
        $subscriber = new WebhookConfigSubscriber($service, $config, new NullLogger(), $this->createMock(MessageBusInterface::class), 'https://shop.example');
        $subscriber->onSystemConfigChanged(new SystemConfigChangedEvent('KarlaDelivery.config.webhookEnabled', false, self::CHANNEL));
    }
    public static function deletionStatuses(): array
    {
        return [[204, true], [404, true], [401, false], [500, false]];
    }

    #[DataProvider('deletionStatuses')]
    public function testDeletionKeepsOriginalOwnerAndChecksHttpStatus(int $status, bool $success): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $saved = ['shopSlug' => 'original', 'apiUsername' => 'original-user', 'apiKey' => 'original-key', 'apiUrl' => 'https://old.example'];
        $config->method('getDomain')->willReturn(['KarlaDelivery.config.webhookRegistration' => ['id' => 'subscription', 'config' => $saved]]);
        $config->expects($success ? self::once() : self::never())->method('set')->with('KarlaDelivery.config.webhookRegistration', null, self::CHANNEL);
        $client = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $client->expects(self::once())->method('request')->with('DELETE', 'https://old.example/v1/shops/original/webhooks/subscription', ['auth_basic' => ['original-user', 'original-key']])->willReturn($response);
        if (! $success) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP ' . $status);
        }
        (new WebhookService($config, $client, new NullLogger()))->deleteWebhook('subscription', self::CHANNEL);
    }

    public function testLegacyChannelSubscriptionIsDeletedFromGlobalShop(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key, ?string $channel = null) => match ($key) {
            'KarlaDelivery.config.webhookUrl' => 'https://shop.example/shop/api/karla/webhooks/legacy',
            'KarlaDelivery.config.shopSlug' => $channel === null ? 'global-shop' : 'new-brand',
            'KarlaDelivery.config.apiUsername' => 'global-user',
            'KarlaDelivery.config.apiKey' => 'global-key',
            'KarlaDelivery.config.apiUrl' => 'https://api.example',
            default => null,
        });
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('request')->with('DELETE', 'https://api.example/v1/shops/global-shop/webhooks/legacy', ['auth_basic' => ['global-user', 'global-key']])->willReturn($response);
        (new WebhookService($config, $client, new NullLogger()))->deleteWebhook('legacy', self::CHANNEL);
    }

    public static function rotatedCredentials(): array
    {
        return [[204, true], [403, false]];
    }

    #[DataProvider('rotatedCredentials')]
    public function testDeletionRetriesRevokedCredentialsWithoutChangingOwner(int $retryStatus, bool $success): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $saved = ['shopSlug' => 'original', 'apiUsername' => 'old-user', 'apiKey' => 'revoked-key', 'apiUrl' => 'https://api.example'];
        $config->method('getDomain')->willReturn(['KarlaDelivery.config.webhookRegistration' => ['id' => 'subscription', 'config' => $saved]]);
        $config->method('get')->willReturnMap([
            ['KarlaDelivery.config.apiUrl', self::CHANNEL, 'https://api.example'],
            ['KarlaDelivery.config.apiUsername', self::CHANNEL, 'rotated-user'],
            ['KarlaDelivery.config.apiKey', self::CHANNEL, 'rotated-key'],
        ]);
        $config->expects($success ? self::once() : self::never())->method('set');
        $client = $this->createMock(HttpClientInterface::class);
        $attempt = 0;
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(function (string $method, string $url, array $options) use (&$attempt, $retryStatus) {
            self::assertSame('https://api.example/v1/shops/original/webhooks/subscription', $url);
            self::assertSame($attempt === 0 ? ['old-user', 'revoked-key'] : ['rotated-user', 'rotated-key'], $options['auth_basic']);
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($attempt++ === 0 ? 401 : $retryStatus);

            return $response;
        });
        if (! $success) {
            $this->expectException(\RuntimeException::class);
        }
        (new WebhookService($config, $client, new NullLogger()))->deleteWebhook('subscription', self::CHANNEL);
    }

}
