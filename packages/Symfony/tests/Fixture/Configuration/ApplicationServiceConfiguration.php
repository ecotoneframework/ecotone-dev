<?php

declare(strict_types=1);

namespace Fixture\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\MediaType;
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
            ->withConsumerMemoryLimit(256)
            ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackOff(1, 2)->maxRetries(4))
            ->withDefaultErrorChannel('errorChannel')
            ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON);
    }
}
