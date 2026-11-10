<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Karla\Delivery\Service\CatalogApiClient;
use Karla\Delivery\Service\CatalogCleanupService;
use Karla\Delivery\Service\ProductSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CatalogCleanupServiceTest extends TestCase
{
    private const ID = '11111111111111111111111111111111';
    private const CHILD = '22222222222222222222222222222222';
    private const OTHER = '33333333333333333333333333333333';

    private function product(string $id, ?string $parent = null, int $children = 0): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId($id);
        $product->setParentId($parent);
        $product->setChildCount($children);

        return $product;
    }

    private function service(array $known, array $children, array $eligible, array $pages, bool $shared = false, array $channelIds = ['a', 'b'], int $deleteStatus = 204, ?Connection $connection = null, ?array $currentEligible = null): array
    {
        $repository = $this->createMock(EntityRepository::class);
        $results = [];
        foreach ([$known, $children, $eligible, $currentEligible ?? $eligible] as $entities) {
            $result = $this->createMock(EntitySearchResult::class);
            $result->method('getIterator')->willReturn(new \ArrayIterator($entities));
            $results[] = $result;
        }
        $read = 0;
        $repository->method('search')->willReturnCallback(static function ($criteria, $context) use (&$results, &$read, $shared, $channelIds) {
            self::assertTrue($context->considerInheritance());
            ++$read;
            if ($read === 3) {
                self::assertSame('active', $criteria->getFilters()[0]->getField());
                self::assertSame('visibilities.salesChannelId', $criteria->getFilters()[1]->getField());
                self::assertSame($shared ? $channelIds : ['a'], $criteria->getFilters()[1]->getValue());
            }

            return array_shift($results);
        });
        $channels = $this->createMock(EntityRepository::class);
        $entities = [];
        foreach ($channelIds as $id) {
            $entity = new SalesChannelEntity();
            $entity->setId($id);
            $entities[] = $entity;
        }
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getIterator')->willReturn(new \ArrayIterator($entities));
        $channels->method('search')->willReturn($result);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'KarlaDelivery.config.productSyncEnabled' => true,
            'KarlaDelivery.config.apiUrl' => 'https://api.example.test',
            'KarlaDelivery.config.apiUsername' => 'user',
            'KarlaDelivery.config.apiKey' => 'key',
            default => null,
        });
        $sync = $this->createMock(ProductSyncService::class);
        $sync->method('getShopSlug')->willReturnCallback(static fn ($channel) => $shared || $channel !== 'b' ? 'shop-a' : 'shop-b');
        $http = $this->createMock(HttpClientInterface::class);
        /** @var \ArrayObject<int, array{string, string}> $requests */
        $requests = new \ArrayObject();
        $http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($requests, &$pages, $deleteStatus) {
            self::assertSame('Basic ' . base64_encode('user:key'), $options['headers']['Authorization']);
            self::assertStringStartsWith('https://api.example.test/v1/shops/shop-a/products', $url);
            self::assertContains($method, ['GET', 'POST']);
            self::assertSame($method === 'POST', isset($options['body']));
            $requests[] = [$method, $url];
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($method === 'GET' ? 200 : $deleteStatus);
            $page = $method === 'GET' ? array_shift($pages) : [];
            $response->method('getContent')->willReturn(is_string($page) ? $page : json_encode($page));

            return $response;
        });

        return [new CatalogCleanupService($repository, $channels, $config, $sync, new CatalogApiClient($http, $config, new NullLogger()), $connection ?? $this->createMock(Connection::class)), $requests];
    }

    public function testCatalogLockUsesPrimaryAndAlwaysReleasesAfterFailure(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->expects(self::once())->method('ensureConnectedToPrimary');
        $calls = [];
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(static function ($sql, $params) use (&$calls) {
            self::assertSame(64, strlen($params[0]));
            $calls[] = [$sql, $params[0]];

            return 1;
        });
        [$service] = $this->service([], [], [], [], connection: $connection);

        try {
            $service->withCatalogLock('a', static function () {
                throw new \RuntimeException('API failure');
            });
            self::fail('The job failure must propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('API failure', $error->getMessage());
        }
        self::assertSame('SELECT GET_LOCK(?, 0)', $calls[0][0]);
        self::assertSame('SELECT RELEASE_LOCK(?)', $calls[1][0]);
        self::assertSame($calls[0][1], $calls[1][1]);
    }

    public function testBusyCatalogDefersWithoutReadingOrDeletingRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(0);
        [$service, $requests] = $this->service([], [], [], [], connection: $connection);

        try {
            $service->withCatalogLock('a', static function () {
                self::fail('Busy work must not run');
            });
            self::fail('Busy catalog must retry');
        } catch (RecoverableMessageHandlingException $error) {
            self::assertSame(5000, $error->getRetryDelay());
            self::assertCount(0, $requests);
        }
    }

    public function testInactiveExistingVariantIsRemovedIdempotently(): void
    {
        [$service, $requests] = $this->service([$this->product(self::ID)], [], [], [[['product_id' => self::ID, 'variant_id' => self::ID]]], deleteStatus: 204);
        $service->retireUnavailable([self::ID], 'a');
        self::assertSame(['GET', 'POST'], array_column($requests->getArrayCopy(), 0));
        self::assertStringContainsString('variant_ids=' . self::ID, $requests[0][1]);
        self::assertStringEndsWith('/' . self::ID . '/variants/' . self::ID . '/archive', $requests[1][1]);
    }

    public function testSharedDestinationKeepsProductsVisibleInTheOtherStorefront(): void
    {
        $product = $this->product(self::ID);
        [$service, $requests] = $this->service([$product], [], [$product], [[['product_id' => self::ID, 'variant_id' => self::ID]]], true, currentEligible: []);
        self::assertSame([], $service->retireUnavailable([self::ID], 'a'));
        self::assertSame(['GET'], array_column($requests->getArrayCopy(), 0));
    }

    public function testSharedDestinationReturnsProductsVisibleInCurrentStorefront(): void
    {
        $product = $this->product(self::ID);
        [$service, $requests] = $this->service([$product], [], [$product], [[['product_id' => self::ID, 'variant_id' => self::ID]]], true);
        self::assertSame([self::ID], $service->retireUnavailable([self::ID], 'a'));
        self::assertSame(['GET'], array_column($requests->getArrayCopy(), 0));
    }

    public function testRetirementIsPerVariantAndRemovesObsoleteParentMappings(): void
    {
        $parent = $this->product(self::ID, null, 2);
        $active = $this->product(self::CHILD, self::ID);
        $inactive = $this->product(self::OTHER, self::ID);
        $rows = [['product_id' => self::ID, 'variant_id' => self::ID], ['product_id' => self::ID, 'variant_id' => self::CHILD], ['product_id' => self::ID, 'variant_id' => self::OTHER], ['product_id' => 'old-parent', 'variant_id' => self::CHILD]];
        [$service, $requests] = $this->service([$parent], [$active, $inactive], [$parent, $active], [$rows]);
        $service->retireUnavailable([self::ID], 'a');
        self::assertSame(['GET', 'POST', 'POST', 'POST'], array_column($requests->getArrayCopy(), 0));
        self::assertStringEndsWith('/old-parent/variants/' . self::CHILD . '/archive', $requests[3][1]);
        self::assertNotContains('https://api.example.test/v1/shops/shop-a/products/' . self::ID . '/variants/' . self::CHILD, array_column($requests->getArrayCopy(), 1));
    }

    public function testUnknownManualIdsAndUnresolvedDestinationsNeverCauseDeletions(): void
    {
        [$service, $requests] = $this->service([], [], [], []);
        $service->retireUnavailable([], 'a');
        $service->retireUnavailable([self::ID], 'a');
        self::assertCount(0, $requests);
        [$service, $requests] = $this->service([$this->product(self::ID)], [], [], [], channelIds: []);
        $service->retireUnavailable([self::ID], 'a');
        self::assertCount(0, $requests);
    }

    public function testLegacyRefreshReturnsOnlyEligibleLeaves(): void
    {
        $product = $this->product(self::ID);
        [$service] = $this->service([$product], [], [$product], [[]]);
        self::assertSame([self::ID], $service->retireUnavailable([self::ID], null));
    }

    public function testInactiveCurrentChannelCannotUpsertAnotherChannelsProducts(): void
    {
        $product = $this->product(self::ID);
        [$service, $requests] = $this->service([$product], [], [$product], [[['product_id' => self::ID, 'variant_id' => self::ID]]], true, ['b']);
        self::assertSame([], $service->retireUnavailable([self::ID], 'a'));
        self::assertSame(['GET'], array_column($requests->getArrayCopy(), 0));
    }

    public function testMissingDestinationCannotReadOrDeleteCatalogRows(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $service = new CatalogCleanupService($this->createMock(EntityRepository::class), $this->createMock(EntityRepository::class), $config, $this->createMock(ProductSyncService::class), new CatalogApiClient($http, $config, new NullLogger()), $this->createMock(Connection::class));
        $this->expectExceptionMessage('Missing catalog destination');
        $service->catalogVariantIds(null);
    }

    public function testAllPagesAreReadBeforeAnyDeletion(): void
    {
        $page = array_fill(0, 100, ['product_id' => self::ID, 'variant_id' => self::ID]);
        [$service, $requests] = $this->service([$this->product(self::ID)], [], [], [$page, [['product_id' => self::ID, 'variant_id' => self::ID]]]);
        $service->retireUnavailable([self::ID], 'a');
        self::assertCount(103, $requests);
        self::assertSame('GET', $requests[0][0]);
        self::assertSame('GET', $requests[1][0]);
        self::assertStringContainsString('page=2', $requests[1][1]);
        self::assertSame('POST', $requests[2][0]);
    }

    public static function invalidResponses(): array
    {
        return ['object' => ['{"error":"not a list"}'], 'missing ids' => ['[{}]'], 'unrequested variant' => ['[{"product_id":"x","variant_id":"unrequested"}]']];
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidRemoteResponsesCannotTriggerCleanup(string $body): void
    {
        [$service, $requests] = $this->service([$this->product(self::ID)], [], [], [$body]);

        try {
            $service->retireUnavailable([self::ID], 'a');
            self::fail('Invalid catalog response must fail the job');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('catalog', strtolower($error->getMessage()));
            self::assertSame(['GET'], array_column($requests->getArrayCopy(), 0));
        }
    }

    public function testBackfillSnapshotsOnlyShopwareShapedVariantIds(): void
    {
        [$service] = $this->service([], [], [], [[['product_id' => self::ID, 'variant_id' => self::ID], ['product_id' => 'manual', 'variant_id' => 'manual'], ['product_id' => self::ID, 'variant_id' => self::ID]]]);
        self::assertSame([self::ID], $service->catalogVariantIds('a'));
    }
}
