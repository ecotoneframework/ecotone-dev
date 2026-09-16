<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\Messaging;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
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
                conversionMediaType: MediaType::createApplicationXPHP(),
                delayable: false
            ),
            // 3 retries for notifications
            ErrorHandlerConfiguration::createWithDeadLetterChannel(
                'errorChannel',
                RetryTemplateBuilder::exponentialBackOff(1000, 10)
                    ->maxRetries(3),
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