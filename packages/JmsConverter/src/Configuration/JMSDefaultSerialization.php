<?php

namespace Ecotone\JMSConverter\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;

/**
 * licence Apache-2.0
 */
class JMSDefaultSerialization
{
    #[ServiceContext]
    public function getDefaultConfig(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON);
    }
}
