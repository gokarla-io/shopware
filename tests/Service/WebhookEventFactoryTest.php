<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\WebhookEventFactory;
use Karla\Delivery\Tests\Fixtures\KarlaWebhookPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\LanguageStorer;
use Shopware\Core\Content\Flow\Dispatching\Storer\MailStorer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\LanguageAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageEntity;

final class WebhookEventFactoryTest extends TestCase
{
    private const ENGLISH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ENGLISH_VARIANT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[DataProvider('orderLanguages')]
    public function testOrderLanguageAndChannelSurviveFlowStorage(
        string $languageId,
        ?string $parentId,
        bool $guest,
        array $expectedChain,
    ): void {
        $context = Context::createDefaultContext();
        $context->addState('webhook-test');
        $context->addExtension('webhook-test', new ArrayStruct(['value' => 'preserved']));
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setLanguageId($languageId);
        $order->setSalesChannelId(Uuid::randomHex());
        $language = new LanguageEntity();
        $language->setId($languageId);
        $language->setParentId($parentId);
        $order->setLanguage($language);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->with(
            self::callback(static fn (Criteria $criteria): bool => $criteria->getIds() === [$order->getId()]
                && $criteria->hasAssociation('language')),
            self::identicalTo($context),
        )->willReturn(new EntitySearchResult('order', 1, new OrderCollection([$order]), null, new Criteria(), $context));
        $payload = $guest ? KarlaWebhookPayloads::shipmentGuest() : KarlaWebhookPayloads::shipment();
        $payload['context']['order']['external_id'] = $order->getId();
        $event = (new WebhookEventFactory($repository))->create($payload, $context);

        self::assertNotSame($context, $event->getContext());
        self::assertSame($expectedChain, $event->getContext()->getLanguageIdChain());
        self::assertSame([Defaults::LANGUAGE_SYSTEM], $context->getLanguageIdChain());
        self::assertTrue($event->getContext()->hasState('webhook-test'));
        self::assertEquals($context->getExtension('webhook-test'), $event->getContext()->getExtension('webhook-test'));
        self::assertEquals($context->getSource(), $event->getContext()->getSource());
        self::assertSame($payload['event_data'], $event->getValues()['karla']);

        // Use Shopware's real storers: MailAware otherwise prevents order fallback.
        $languageStorer = new LanguageStorer();
        $mailStorer = new MailStorer();
        $store = $mailStorer->store($event, $languageStorer->store($event, []));
        $flow = new StorableFlow($event->getName(), $event->getContext(), $store);
        $languageStorer->restore($flow);
        $mailStorer->restore($flow);
        self::assertSame($languageId, $flow->getData(LanguageAware::LANGUAGE_ID));
        self::assertSame($order->getSalesChannelId(), $flow->getData(MailAware::SALES_CHANNEL_ID));
        self::assertSame($expectedChain, $flow->getContext()->getLanguageIdChain());
        self::assertSame($event->getMailStruct()->getRecipients(), $flow->getData(MailAware::MAIL_STRUCT)->getRecipients());
    }

    public static function orderLanguages(): iterable
    {
        yield 'system language' => [Defaults::LANGUAGE_SYSTEM, null, false, [Defaults::LANGUAGE_SYSTEM]];
        yield 'English order on system-language API request' => [self::ENGLISH, null, false, [self::ENGLISH, Defaults::LANGUAGE_SYSTEM]];
        yield 'English guest order' => [self::ENGLISH, null, true, [self::ENGLISH, Defaults::LANGUAGE_SYSTEM]];
        yield 'regional language inherits English' => [self::ENGLISH_VARIANT, self::ENGLISH, false, [self::ENGLISH_VARIANT, self::ENGLISH, Defaults::LANGUAGE_SYSTEM]];
        yield 'system parent is not duplicated' => [self::ENGLISH, Defaults::LANGUAGE_SYSTEM, false, [self::ENGLISH, Defaults::LANGUAGE_SYSTEM]];
    }

    public function testMissingOrderFailsInsteadOfDispatchingInWrongLanguage(): void
    {
        $context = Context::createDefaultContext();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(new EntitySearchResult('order', 0, new OrderCollection(), null, new Criteria(), $context));
        $payload = KarlaWebhookPayloads::shipment();
        $payload['context']['order']['external_id'] = Uuid::randomHex();
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Shopware order not found');
        (new WebhookEventFactory($repository))->create($payload, $context);
    }

    public function testInvalidOrderIdDoesNotQueryRepository(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid Shopware order ID');
        (new WebhookEventFactory($repository))->create(KarlaWebhookPayloads::shipment(), Context::createDefaultContext());
    }
}
