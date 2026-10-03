<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Unit;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;

use function PHPStan\Testing\assertType;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * licence Apache-2.0
 * @internal
 */
final class SqsBackedMessageChannelBuilderTypeTest extends TypeInferenceTestCase
{
    public static function chained_channel_configuration(): iterable
    {
        yield from self::gatherAssertTypes(__FILE__);
    }

    #[DataProvider('chained_channel_configuration')]
    public function test_every_with_method_keeps_the_sqs_backed_builder_type_for_static_analysis(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    private static function databaseChannel(): void
    {
        $channel = SqsBackedMessageChannelBuilder::create('notifications');

        assertType(SqsBackedMessageChannelBuilder::class, $channel->withHeaderMapping('*'));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withFinalFailureStrategy(FinalFailureStrategy::RESEND));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withDefaultTimeToLive(1000));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withDefaultDeliveryDelay(1000));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withDefaultConversionMediaType('application/json'));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withAutoDeclare(false));
        assertType(SqsBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100)->withHighThroughputPublishing());
    }
}
