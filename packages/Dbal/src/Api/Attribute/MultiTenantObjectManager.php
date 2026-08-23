<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Api\Attribute;

use Attribute;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class MultiTenantObjectManager extends Reference
{
    public function __construct($referenceName = MultiTenantConnectionFactory::class)
    {
        parent::__construct($referenceName, 'service.getManager()');
    }
}
