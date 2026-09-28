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
            'own channel' => [true, 'channel-secret', self::CHANNEL, 'callback', 200],
            'wrong secret' => [true, 'global-secret', self::CHANNEL, 'callback', 401],
            'another channel order' => [true, 'channel-secret', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'callback', 403],
            'disabled channel' => [false, 'channel-secret', self::CHANNEL, 'callback', 403],
            'unknown callback' => [true, 'channel-secret', self::CHANNEL, 'unknown', 404],
        ];
    }

    #[DataProvider('callbacks')]
    public function testCallbackIsolation(bool $enabled, string $secret, string $orderChannel, string $id, int $status): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getDomain')->with('KarlaDelivery.config', self::CHANNEL, false)->willReturn([
            'KarlaDelivery.config.webhookEnabled' => $enabled,
            'KarlaDelivery.config.webhookSecret' => 'channel-secret',
            'KarlaDelivery.config.webhookUrl' => 'https://shop.example/api/karla/webhooks/' . self::CHANNEL . '/callback',
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
}
