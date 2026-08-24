<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Attribute;
use Ecotone\Api\ServiceActivator;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class OnTenantActivation extends ServiceActivator
{
    public function __construct()
    {
        parent::__construct(HeaderBasedMultiTenantConnectionFactory::TENANT_ACTIVATED_CHANNEL_NAME, '', '', false, []);
    }
}
