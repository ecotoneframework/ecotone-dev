<?php

declare(strict_types=1);

namespace Messaging\Unit\Channel\DynamicChannel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\DynamicChannel\RoundRobinChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Channel\DynamicChannel\DynamicChannelResolver;
use Test\Ecotone\Messaging\Fixture\Handler\SuccessServiceActivator;

final class RoundRobinMessageChannelBuilderTest extends TestCase
{
    public function test_sending_and_receiving_from_single_channel(): void
    {
        $successServiceActivator = new SuccessServiceActivator();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [$successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                RoundRobinChannelBuilder::create('async_channel', ['channel_one']),
                SimpleMessageChannelBuilder::createQueueChannel('channel_one')
            ]
        );

        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);
        $this->assertSame(0, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);
        $ecotoneLite->run('async_channel');
        $this->assertSame(2, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }

    public function test_sending_and_receiving_from_multiple_channels(): void
    {
        $successServiceActivator = new SuccessServiceActivator();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [$successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                RoundRobinChannelBuilder::createWithDifferentChannels('async_channel', sendingChannelNames: ['channel_one'], receivingChannelNames: ['channel_two', 'channel_one']),
                SimpleMessageChannelBuilder::createQueueChannel('channel_one'),
                SimpleMessageChannelBuilder::createQueueChannel('channel_two'),
            ]
        );

        /** Send to channel_one */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);

        /** We are fetching with channel_two */
        $ecotoneLite->run('async_channel');
        $this->assertSame(0, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** We are fetching with channel_one */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }

    public function test_sending_and_receiving_from_internal_channels(): void
    {
        $successServiceActivator = new SuccessServiceActivator();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [$successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                RoundRobinChannelBuilder::createWithDifferentChannels('async_channel',
                    sendingChannelNames: ['channel_one'],
                    receivingChannelNames: ['channel_two', 'channel_one'],
                    internalMessageChannels: [
                        SimpleMessageChannelBuilder::createQueueChannel('channel_one'),
                        SimpleMessageChannelBuilder::createQueueChannel('channel_two')
                    ]
                ),
            ]
        );

        /** Send to channel_one */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);

        /** We are fetching with channel_two */
        $ecotoneLite->run('async_channel');
        $this->assertSame(0, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** We are fetching with channel_one */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }

    public function test_sending_and_receiving_from_internal_channels_with_custom_name(): void
    {
        $successServiceActivator = new SuccessServiceActivator();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SuccessServiceActivator::class],
            [$successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                RoundRobinChannelBuilder::createWithDifferentChannels('async_channel',
                    sendingChannelNames: ['x'],
                    receivingChannelNames: ['x', 'y'],
                    internalMessageChannels: [
                        'x' => SimpleMessageChannelBuilder::createQueueChannel('channel_one'),
                        'y' => SimpleMessageChannelBuilder::createQueueChannel('channel_two')
                    ]
                ),
            ]
        );

        /** Send to x */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);
        /** Send to y */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);

        /** We are fetching with x */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** We are fetching with y */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** We are fetching with x */
        $ecotoneLite->run('async_channel');
        $this->assertSame(2, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }
}