<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Unit;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;

use function PHPStan\Testing\assertType;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpBackedMessageChannelBuilderTypeTest extends TypeInferenceTestCase
{
    public static function chained_channel_configuration(): iterable
    {
        yield from self::gatherAssertTypes(__FILE__);
    }

    #[DataProvider('chained_channel_configuration')]
    public function test_every_with_method_keeps_the_amqp_backed_builder_type_for_static_analysis(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    private static function databaseChannel(): void
    {
        $channel = AmqpBackedMessageChannelBuilder::create('notifications');

        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withHeaderMapping('*'));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withFinalFailureStrategy(FinalFailureStrategy::RESEND));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withDefaultTimeToLive(1000));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withDefaultDeliveryDelay(1000));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withDefaultConversionMediaType('application/json'));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withAutoDeclare(false));
        assertType(AmqpBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100)->withPublisherConfirms(true)->withHighThroughputPublishing()->withDelayStrategy('delay'));
    }
}
