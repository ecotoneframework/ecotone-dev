<?php

namespace Ecotone\JMSConverter\Configuration;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Config\ServiceConfiguration;
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
