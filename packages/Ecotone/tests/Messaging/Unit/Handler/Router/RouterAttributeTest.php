<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Router;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class RouterAttributeTest extends TestCase
{
    public function test_routing_message_to_a_single_channel(): void
    {
        $handler = new CustomRouterHandler();
        $handler->channelsToPick = 'buyChannel';
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendDirectToChannel(CustomRouterHandler::ROUTER_CHANNEL, 'some');

        $this->assertTrue($handler->buyChannelCalled);
        $this->assertFalse($handler->sellChannelCalled);
    }

    public function test_routing_message_to_multiple_channels(): void
    {
        $handler = new CustomRouterHandler();
        $handler->channelsToPick = ['buyChannel', 'sellChannel'];
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendDirectToChannel(CustomRouterHandler::ROUTER_CHANNEL, 'some');

        $this->assertTrue($handler->buyChannelCalled);
        $this->assertTrue($handler->sellChannelCalled);
    }

    public function test_throwing_exception_when_resolution_is_required_but_router_picks_nothing(): void
    {
        $handler = new CustomRouterHandler();
        $handler->channelsToPick = [];
        $ecotone = $this->bootstrap($handler);

        $this->expectException(DestinationResolutionException::class);

        $ecotone->sendDirectToChannel(CustomRouterHandler::ROUTER_CHANNEL, 'some');
    }

    public function test_not_throwing_exception_when_resolution_is_not_required_and_router_picks_nothing(): void
    {
        $handler = new NotRequiredRouterHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [NotRequiredRouterHandler::class],
            [$handler],
        );

        $ecotone->sendDirectToChannel(NotRequiredRouterHandler::ROUTER_CHANNEL, 'some');

        $this->assertFalse($handler->buyChannelCalled);
    }

    private function bootstrap(CustomRouterHandler $handler)
    {
        return EcotoneLite::bootstrapFlowTesting(
            [CustomRouterHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel('unused'),
            ]),
        );
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class CustomRouterHandler
{
    public const ROUTER_CHANNEL = 'router.input';

    public string|array $channelsToPick = [];
    public bool $buyChannelCalled = false;
    public bool $sellChannelCalled = false;

    #[Router(self::ROUTER_CHANNEL)]
    public function pick(string $message): string|array
    {
        return $this->channelsToPick;
    }

    #[InternalHandler('buyChannel')]
    public function handleBuy(): void
    {
        $this->buyChannelCalled = true;
    }

    #[InternalHandler('sellChannel')]
    public function handleSell(): void
    {
        $this->sellChannelCalled = true;
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class NotRequiredRouterHandler
{
    public const ROUTER_CHANNEL = 'router.notRequired.input';

    public bool $buyChannelCalled = false;

    #[Router(self::ROUTER_CHANNEL, isResolutionRequired: false)]
    public function pick(string $message): array
    {
        return [];
    }

    #[InternalHandler('buyChannel')]
    public function handleBuy(): void
    {
        $this->buyChannelCalled = true;
    }
}
