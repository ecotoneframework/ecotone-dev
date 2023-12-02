<?php

declare(strict_types=1);

namespace Messaging\Unit\Channel\DynamicChannel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Channel\DynamicChannel\DynamicChannelResolver;
use Test\Ecotone\Messaging\Fixture\Handler\SuccessServiceActivator;

final class DynamicMessageChannelBuilderTest extends TestCase
{
    public function test_sending_and_receiving_from_single_channel(): void
    {
        $dynamicChannelResolver = new DynamicChannelResolver(
            ['channel_one'],
            ['channel_one']
        );
        $successServiceActivator = new SuccessServiceActivator();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DynamicChannelResolver::class, SuccessServiceActivator::class],
            [$dynamicChannelResolver, $successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                DynamicMessageChannelBuilder::create('async_channel', 'dynamicChannel.receive', 'dynamicChannel.send'),
                SimpleMessageChannelBuilder::createQueueChannel('channel_one')
            ]
        );

        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);
        $this->assertSame(0, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** Sending to null channel */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);
        /** Receiving from null channel */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }

    public function test_sending_and_receiving_from_multiple_channels(): void
    {
        $dynamicChannelResolver = new DynamicChannelResolver(
            ['channel_one', 'channel_two'],
            ['channel_two', 'channel_one', 'channel_three', 'channel_two']
        );
        $successServiceActivator = new SuccessServiceActivator();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DynamicChannelResolver::class, SuccessServiceActivator::class],
            [$dynamicChannelResolver, $successServiceActivator],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    PollingMetadata::create('async_channel')
                        ->setExecutionAmountLimit(1)
                ]),
            enableAsynchronousProcessing: [
                DynamicMessageChannelBuilder::create('async_channel', 'dynamicChannel.send', 'dynamicChannel.receive'),
                SimpleMessageChannelBuilder::createQueueChannel('channel_one'),
                SimpleMessageChannelBuilder::createQueueChannel('channel_two'),
                SimpleMessageChannelBuilder::createQueueChannel('channel_three'),
            ]
        );

        /** Sending to channel one */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);

        /** Receiving from channel two */
        $ecotoneLite->run('async_channel');
        $this->assertSame(0, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** Receiving from channel one */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** Sending to channel two */
        $ecotoneLite->sendDirectToChannel('handle_channel', ['test']);

        /** Receiving from channel three */
        $ecotoneLite->run('async_channel');
        $this->assertSame(1, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));

        /** Receiving from channel two */
        $ecotoneLite->run('async_channel');
        $this->assertSame(2, $ecotoneLite->sendQueryWithRouting('get_number_of_calls'));
    }
}