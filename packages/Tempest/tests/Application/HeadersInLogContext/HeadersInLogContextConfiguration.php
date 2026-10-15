<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application\HeadersInLogContext;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;

/**
 * licence Apache-2.0
 */
final class HeadersInLogContextConfiguration
{
    #[ServiceContext]
    public function serviceConfiguration(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withHeadersInLogContext(['userId']);
    }
}
