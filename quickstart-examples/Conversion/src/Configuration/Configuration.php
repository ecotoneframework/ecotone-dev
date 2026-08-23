<?php

namespace App\Conversion\Configuration;

use Ecotone\JMSConverter\Api\ExtensionObject\JMSConverterConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

class Configuration
{
    #[ServiceContext]
    public function getJmsConfiguration()
    {
        return JMSConverterConfiguration::createWithDefaults()
                ->withDefaultNullSerialization(true)
                ->withNamingStrategy("identicalPropertyNamingStrategy");
    }
}