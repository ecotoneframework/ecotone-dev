<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Api\Attribute;

use Attribute;
use Ecotone\Api\Attribute\ServiceActivator;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class OnTenantDeactivation extends ServiceActivator
{
    public function __construct()
    {
        parent::__construct(HeaderBasedMultiTenantConnectionFactory::TENANT_DEACTIVATED_CHANNEL_NAME, '', '', false, []);
    }
}
