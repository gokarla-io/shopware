<?php

declare(strict_types=1);

namespace Karla\Delivery\Service;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldsInstaller
{
    private const CUSTOM_FIELD_SET_NAME = 'karla_order_tracking';

    public function __construct(private readonly EntityRepository $customFieldSetRepository)
    {
    }

    public function install(Context $context): void
    {
        $this->customFieldSetRepository->upsert([
            [
                'id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_NAME),
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'active' => true,
                'global' => true,
                'config' => [
                    'label' => [
                        'de-DE' => 'Karla Sendungsverfolgung',
                        'en-GB' => 'Karla order tracking',
                    ],
                ],
                'relations' => [
                    [
                        'id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_NAME . '_order_relation'),
                        'entityName' => OrderDefinition::ENTITY_NAME,
                    ],
                ],
                'customFields' => [
                    [
                        'id' => Uuid::fromStringToHex(TrackpageUrlService::CUSTOM_FIELD_NAME),
                        'name' => TrackpageUrlService::CUSTOM_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'de-DE' => 'Signierte Karla Tracking-URL',
                                'en-GB' => 'Signed Karla tracking URL',
                            ],
                            'componentName' => 'sw-field',
                            'customFieldType' => CustomFieldTypes::TEXT,
                            'customFieldPosition' => 1,
                        ],
                    ],
                ],
            ],
        ], $context);
    }

    public function uninstall(Context $context): void
    {
        $this->customFieldSetRepository->delete([
            ['id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_NAME)],
        ], $context);
    }
}
