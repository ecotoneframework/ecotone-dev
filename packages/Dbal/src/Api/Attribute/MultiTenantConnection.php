<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Api\Attribute;

use Attribute;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Api\Attribute\Parameter\Reference;

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
