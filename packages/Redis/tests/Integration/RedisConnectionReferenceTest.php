<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionReference;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Redis\Connection\RedisConnectionFactory;
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
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::REDIS_PACKAGE])
                ->withExtensionObjects([
                    RedisBackedMessageChannelBuilder::create($queue_name),
                ])
        );

        $ecotone->getMessageChannel($queue_name)->send(MessageBuilder::withPayload('milk')->build());

        self::assertSame('milk', $ecotone->getMessageChannel($queue_name)->receive()->getPayload());
    }
}
