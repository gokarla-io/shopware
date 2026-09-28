<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\MigrationProtectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class MigrationProtectionServiceTest extends TestCase
{
    #[DataProvider('cutoffs')]
    public function testOriginalOrderDatePolicy(mixed $cutoff, string $date, bool $suppressed, bool $invalid = false): void
    {
        $order = new OrderEntity();
        $order->setId(str_repeat('a', 32));
        $order->setSalesChannelId(str_repeat('b', 32));
        $order->setOrderDateTime(new \DateTimeImmutable($date));
        $order->setUpdatedAt(new \DateTimeImmutable('2030-01-01'));
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['KarlaDelivery.config.migrationProtectionEnabled', $order->getSalesChannelId(), true],
            ['KarlaDelivery.config.migrationOrderCutoff', $order->getSalesChannelId(), $cutoff],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($invalid ? self::once() : self::never())->method('error');
        $logger->expects($suppressed && ! $invalid ? self::once() : self::never())->method('info');
        self::assertSame($suppressed, (new MigrationProtectionService($config, $logger))->shouldSuppress($order));
    }

    public static function cutoffs(): iterable
    {
        yield 'old' => ['2026-03-11T00:00:00Z', '2026-03-10T23:59:59Z', true];
        yield 'boundary' => ['2026-03-11T00:00:00Z', '2026-03-11T00:00:00Z', false];
        yield 'new' => ['2026-03-11T00:00:00Z', '2026-09-24T00:00:00Z', false];
        yield 'offset boundary' => ['2026-03-11T01:00:00+01:00', '2026-03-11T00:00:00Z', false];
        yield 'admin milliseconds' => ['2026-03-11T00:00:00.000Z', '2026-03-10T23:59:59Z', true];
        foreach ([null, '', false, [], 'tomorrow', '2026-03-11', '2026-03-11T00:00:00', '2026-02-30T00:00:00Z', '2026-99-11T00:00:00Z'] as $i => $invalid) {
            yield 'invalid ' . $i => [$invalid, '2026-09-24', true, true];
        }
    }

    public function testSettingsAreResolvedPerOrderAndCanBeToggledWithoutRecreatingService(): void
    {
        $order = new OrderEntity();
        $order->setId(str_repeat('a', 32));
        $order->setSalesChannelId(str_repeat('b', 32));
        $order->setOrderDateTime(new \DateTimeImmutable('2024-01-01'));
        $enabled = false;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static function (string $key, ?string $channel) use (&$enabled): mixed {
            if ($channel !== str_repeat('b', 32)) {
                return null;
            }

            return str_ends_with($key, 'Enabled') ? $enabled : '2026-03-11T00:00:00Z';
        });
        $service = new MigrationProtectionService($config, $this->createMock(LoggerInterface::class));
        self::assertFalse($service->shouldSuppress($order));
        $enabled = true;
        self::assertTrue($service->shouldSuppress($order));
        $order->setSalesChannelId(str_repeat('c', 32));
        self::assertFalse($service->shouldSuppress($order));
        $order->setSalesChannelId(str_repeat('b', 32));
        $enabled = false;
        self::assertFalse($service->shouldSuppress($order));
    }
}
