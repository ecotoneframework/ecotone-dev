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
    #[ServiceContext]
    public function repositories()
    {
        /**
         * This is dbal asynchronous channel (ecotone/dbal), which provides us with Outbox Pattern.
         * https://docs.ecotone.tech/modelling/asynchronous-handling
         */
        return InMemoryRepositoryBuilder::createForSetOfStateStoredAggregates([Order::class]);
    }
}