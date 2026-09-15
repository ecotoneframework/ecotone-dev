<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\NonPollableChannel\DirectChannelPayload;
use Test\Ecotone\Messaging\Fixture\NonPollableChannel\DirectChannelService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class NonPollableChannelInterceptorTest extends TestCase
{
    public function test_sending_to_direct_channel_inside_command_handler_is_handled_inline(): void
    {
        $service = new DirectChannelService();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DirectChannelService::class, DirectChannelPayload::class],
            [$service],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createDirectMessageChannel(DirectChannelService::DIRECT_CHANNEL),
                ])
        );

        $ecotone->sendCommandWithRouting(DirectChannelService::TRIGGER_ROUTING_KEY, 'first');

        $this->assertSame(['first'], $service->handledDuringCommandHandling);
        $this->assertSame('handled-first', $service->replyDuringCommandHandling);
    }

    public function test_sending_object_to_direct_channel_keeps_payload_type(): void
    {
        $service = new DirectChannelService();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DirectChannelService::class, DirectChannelPayload::class],
            [$service],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createDirectMessageChannel(DirectChannelService::DIRECT_CHANNEL),
                ])
        );

        $ecotone->sendCommandWithRouting(DirectChannelService::TRIGGER_OBJECT_ROUTING_KEY, 'second');

        $this->assertSame([DirectChannelPayload::class], $service->handledPayloadTypes);
    }
}
