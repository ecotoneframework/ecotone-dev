<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Laravel\Config\LaravelConnectionReference;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbalConfiguration()
    {
        return [
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnConsoleCommands(true),
            LaravelConnectionReference::defaultConnection('db_connection')
        ];
    }
}