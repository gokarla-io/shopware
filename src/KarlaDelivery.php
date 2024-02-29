<?php

declare(strict_types=1);

namespace Karla\Delivery;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class KarlaDelivery extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
    }

    public function activate(ActivateContext $activateContext): void
    {
    }

    public function update(UpdateContext $updateContext): void
    {
    }
}
