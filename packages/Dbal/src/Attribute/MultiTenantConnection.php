<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class MultiTenantConnection extends Reference
{
    public function __construct($referenceName = MultiTenantConnectionFactory::class)
    {
        parent::__construct($referenceName, 'service.getConnection()');
    }
}
