<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;

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
    public function instantRetryConfiguration(): InstantRetryConfiguration
    {
        return InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false);
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
