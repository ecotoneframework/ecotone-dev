<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\ServiceActivator;

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
