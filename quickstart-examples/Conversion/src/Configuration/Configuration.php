<?php

namespace App\Conversion\Configuration;

use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

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