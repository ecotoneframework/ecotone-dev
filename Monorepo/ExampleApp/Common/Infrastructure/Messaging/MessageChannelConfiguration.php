<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\Messaging;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryConfiguration;
use Monorepo\ExampleApp\Common\Domain\Order\Order;

final class MessageChannelConfiguration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous";

    #[ServiceContext]
    public function configuration()
    {
        return ServiceConfiguration::createWithDefaults()
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]));
    }

    #[ServiceContext]
    public function asynchronousChannel()
    {
        /**
         * This is dbal asynchronous channel (ecotone/dbal), which provides us with Outbox Pattern.
         * https://docs.ecotone.tech/modelling/asynchronous-handling
         */
        return SimpleMessageChannelBuilder::createQueueChannel(self::ASYNCHRONOUS_CHANNEL);
    }

    #[ServiceContext]
    public function repositories()
    {
        /**
         * This is dbal asynchronous channel (ecotone/dbal), which provides us with Outbox Pattern.
         * https://docs.ecotone.tech/modelling/asynchronous-handling
         */
        return InMemoryRepositoryBuilder::createForSetOfStateStoredAggregates([Order::class]);
    }

    #[ServiceContext]
    public function asynchronousErrorHandling()
    {
        /**
         * This provides retries and storage into dbal dead letter
         * You may try it by throwing exception from Asynchronous Event Handler
         */
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            "errorChannel",
            RetryTemplateBuilder::fixedBackOff(10)
                ->maxRetryAttempts(2),
            "dbal_dead_letter"
        );
    }

    #[ServiceContext]
    public function retryStrategy()
    {
        /**
         * This provides instant retries for Command Bus
         * You may try it by throwing exception from Synchronous Command Handler
         */
        return InstantRetryConfiguration::createWithDefaults()
            ->withCommandBusRetry(
                true, // is enabled
                3, // max retries
                [] // list of exceptions to be retried, leave empty if all should be retried
            );
    }
}