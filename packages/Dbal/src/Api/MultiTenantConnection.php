<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Attribute;
use Ecotone\Api\Reference;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class MultiTenantConnection extends Reference
{
    public function __construct($referenceName = MultiTenantConnectionFactory::class)
    {
        parent::__construct($referenceName, 'service.getConnection()');
    }
}
