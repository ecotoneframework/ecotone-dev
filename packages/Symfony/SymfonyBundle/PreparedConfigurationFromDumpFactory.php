<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\PreparedConfiguration;

class PreparedConfigurationFromDumpFactory
{
    public static function get(string $filename): PreparedConfiguration
    {
        return require $filename;
    }
}