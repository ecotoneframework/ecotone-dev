<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\Messaging;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryConfiguration;
use Monorepo\ExampleApp\Common\Domain\Order\Order;

final class MessageChannelConfiguration
{
    #[ServiceContext]
    public function configuration()
    {
        return [
            InMemoryRepositoryBuilder::createForSetOfStateStoredAggregates([Order::class]),
            SimpleMessageChannelBuilder::createQueueChannel(
                'notifications',
                conversionMediaType: MediaType::createApplicationXPHP()
            ),
            // 3 retries for notifications
            ErrorHandlerConfiguration::createWithDeadLetterChannel(
                'errorChannel',
                RetryTemplateBuilder::exponentialBackoff(1000, 10)
                    ->maxRetryAttempts(3),
                'default_dead_letter'
            ),
            SimpleMessageChannelBuilder::createQueueChannel(
                'delivery',
                conversionMediaType: MediaType::createApplicationXPHP()
            ),
            // No retries push directly
            PollingMetadata::create('delivery')
                ->setErrorChannelName('custom_dead_letter'),
        ];
    }
}