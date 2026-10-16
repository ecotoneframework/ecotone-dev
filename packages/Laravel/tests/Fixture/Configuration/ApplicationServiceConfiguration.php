<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;

/**
 * licence Apache-2.0
 */
final class ApplicationServiceConfiguration
{
    #[ServiceContext]
    public function serviceConfiguration(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackOff(1, 2)->maxRetries(4));
    }
}
