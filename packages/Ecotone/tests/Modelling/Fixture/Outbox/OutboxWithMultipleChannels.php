<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Outbox;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CombinedMessageChannel;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class OutboxWithMultipleChannels
{
    private int $amount = 0;

    #[Asynchronous(['outbox', 'rabbitMQ'])]
    #[CommandHandler('outboxWithMultipleChannels', endpointId: 'outboxMultipleChannelsId')]
    public function handle(int $amount): void
    {
        $this->amount = $amount;
    }

    #[Asynchronous(['outbox_rabbit'])]
    #[CommandHandler('outboxWithCombinedChannels', endpointId: 'outboxCombinedMessageChannelsId')]
    public function handleWithCombinedMessageChannel(int $amount): void
    {
        $this->amount = $amount;
    }

    #[QueryHandler('getResult')]
    public function getResult(): int
    {
        return $this->amount;
    }

    #[ServiceContext]
    public function getChannels()
    {
        /** faking asynchronous message channels */
        return [
            SimpleMessageChannelBuilder::createQueueChannel('outbox'),
            PollingMetadata::create('outbox')->withTestingSetup(),
            SimpleMessageChannelBuilder::createQueueChannel('rabbitMQ'),
            PollingMetadata::create('rabbitMQ')->withTestingSetup(),
        ];
    }

    #[ServiceContext]
    public function combinedMessageChannel(): CombinedMessageChannel
    {
        return CombinedMessageChannel::create(
            'outbox_rabbit',
            ['outbox', 'rabbitMQ'],
        );
    }
}
