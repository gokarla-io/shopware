<?php

namespace PHPSTORM_META {
    override(
        \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::search(0),
        map([
            'order.repository' => \Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult::class,
        ])
    );
}
