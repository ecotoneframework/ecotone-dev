<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Api\ExtensionObject\SymfonyConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbalConfiguration()
    {
        return [
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnConsoleCommands(true),
            SymfonyConnectionReference::defaultConnection('connection')
        ];
    }
}