<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Unit;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;

use function PHPStan\Testing\assertType;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * licence Apache-2.0
 * @internal
 */
final class RedisBackedMessageChannelBuilderTypeTest extends TypeInferenceTestCase
{
    public static function chained_channel_configuration(): iterable
    {
        yield from self::gatherAssertTypes(__FILE__);
    }

    #[DataProvider('chained_channel_configuration')]
    public function test_every_with_method_keeps_the_redis_backed_builder_type_for_static_analysis(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    private static function databaseChannel(): void
    {
        $channel = RedisBackedMessageChannelBuilder::create('notifications');

        assertType(RedisBackedMessageChannelBuilder::class, $channel->withHeaderMapping('*'));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withFinalFailureStrategy(FinalFailureStrategy::RESEND));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withDefaultTimeToLive(1000));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withDefaultDeliveryDelay(1000));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withDefaultConversionMediaType('application/json'));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withAutoDeclare(false));
        assertType(RedisBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100)->withHighThroughputPublishing());
    }
}
