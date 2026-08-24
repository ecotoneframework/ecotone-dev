<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\Symfony\SymfonyConnectionReference;

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