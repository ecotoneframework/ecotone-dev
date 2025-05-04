<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
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
                 ->withDoctrineORMRepositories(true)
                 ->withDeadLetter(true);
    }

    #[ServiceContext]
    public function databaseChannel(): SymfonyMessengerMessageChannelBuilder
    {
        return SymfonyMessengerMessageChannelBuilder::create('notifications');
    }

    #[ServiceContext]
    public function notificationPollingMetadata(): PollingMetadata
    {
        return PollingMetadata::create('notifications')
            ->setExecutionTimeLimitInMilliseconds(1000)
            ->setHandledMessageLimit(10)
            ->setErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }
}
