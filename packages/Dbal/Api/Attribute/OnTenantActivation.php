<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class OnTenantActivation extends InternalHandler
{
    public function __construct()
    {
        parent::__construct(HeaderBasedMultiTenantConnectionFactory::TENANT_ACTIVATED_CHANNEL_NAME);
    }
}
