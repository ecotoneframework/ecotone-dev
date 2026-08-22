<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Config;

final class OrdersMultiTenancyConfig
{
    public function __construct(
        public string $topicReferenceName = 'dynamicOrdersTopic',
    ) {
    }
}
