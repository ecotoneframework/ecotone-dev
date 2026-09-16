<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Attribute;
use Ecotone\Api\InternalHandler;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class OnTenantDeactivation extends InternalHandler
{
    public function __construct()
    {
        parent::__construct(HeaderBasedMultiTenantConnectionFactory::TENANT_DEACTIVATED_CHANNEL_NAME);
    }
}
