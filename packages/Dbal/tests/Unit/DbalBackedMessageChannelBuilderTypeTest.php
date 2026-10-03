<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Unit;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;

use function PHPStan\Testing\assertType;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalBackedMessageChannelBuilderTypeTest extends TypeInferenceTestCase
{
    public static function chained_channel_configuration(): iterable
    {
        yield from self::gatherAssertTypes(__FILE__);
    }

    #[DataProvider('chained_channel_configuration')]
    public function test_every_with_method_keeps_the_dbal_backed_builder_type_for_static_analysis(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    private static function databaseChannel(): void
    {
        $channel = DbalBackedMessageChannelBuilder::create('notifications');

        assertType(DbalBackedMessageChannelBuilder::class, $channel->withHeaderMapping('*'));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withFinalFailureStrategy(FinalFailureStrategy::RESEND));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withDefaultTimeToLive(1000));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withDefaultDeliveryDelay(1000));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withDefaultConversionMediaType('application/json'));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withAutoDeclare(false));
        assertType(DbalBackedMessageChannelBuilder::class, $channel->withReceiveTimeout(100)->withHighThroughputPublishing());
    }
}
