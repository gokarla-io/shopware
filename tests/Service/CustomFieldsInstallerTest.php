<?php

declare(strict_types=1);

namespace Karla\Delivery\Tests\Service;

use Karla\Delivery\Service\CustomFieldsInstaller;
use Karla\Delivery\Service\TrackpageUrlService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldsInstallerTest extends TestCase
{
    public function testInstallUpsertsOrderCustomFieldSet(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $context = Context::createDefaultContext();
        $repository->expects($this->once())
            ->method('upsert')
            ->with(
                $this->callback(function (array $payload): bool {
                    $set = $payload[0];
                    $field = $set['customFields'][0];
                    $relation = $set['relations'][0];

                    return $set['name'] === 'karla_order_tracking'
                        && $set['active'] === true
                        && $relation['entityName'] === OrderDefinition::ENTITY_NAME
                        && $field['name'] === TrackpageUrlService::CUSTOM_FIELD_NAME
                        && $field['type'] === CustomFieldTypes::TEXT;
                }),
                $context
            );

        (new CustomFieldsInstaller($repository))->install($context);
    }

    public function testUninstallDeletesCustomFieldSet(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $context = Context::createDefaultContext();
        $repository->expects($this->once())
            ->method('delete')
            ->with([
                ['id' => Uuid::fromStringToHex('karla_order_tracking')],
            ], $context);

        (new CustomFieldsInstaller($repository))->uninstall($context);
    }
}
