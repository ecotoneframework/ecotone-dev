<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Redis\RedisConnectionReference;
use Ecotone\Test\ChannelTestSupport;
use Ecotone\Test\StaticMessageBuilder;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Redis\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class RedisConnectionReferenceTest extends ConnectionTestCase
{
    public function test_channel_publishes_and_consumes_using_only_ecotone_owned_redis_connection_classes(): void
    {
        $queue_name = Uuid::v7()->toRfc4122();
        $connection_factory = new RedisConnectionFactory(
            getenv('REDIS_DSN') ?: 'redis://localhost:6379'
        );

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                RedisConnectionReference::DEFAULT => $connection_factory,
            ],
            extensionObjects: [RedisBackedMessageChannelBuilder::create($queue_name)],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
        );

        ChannelTestSupport::of($ecotone)->getMessageChannel($queue_name)->send(StaticMessageBuilder::withPayload('milk')->build());

        self::assertSame('milk', ChannelTestSupport::of($ecotone)->getMessageChannel($queue_name)->receive()->getPayload());
    }
}
