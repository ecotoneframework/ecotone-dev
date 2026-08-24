<?php

namespace App\Conversion\Configuration;

use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\ServiceContext;

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