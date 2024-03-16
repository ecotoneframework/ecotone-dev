<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('defined_connection');
    }

    #[ServiceContext]
    public function tenantAConnection(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                 ->withDoctrineORMRepositories(true);
    }

    #[ServiceContext]
    public function databaseChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('notifications');
    }
}
