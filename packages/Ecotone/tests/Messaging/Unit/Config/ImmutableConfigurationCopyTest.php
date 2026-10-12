<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Closure;
use Ecotone\Api\ExtensionObject\ChannelInitializationConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ImmutableConfigurationCopyTest extends TestCase
{
    public function test_channel_initialization_configuration_copy_changes_only_the_named_field(): void
    {
        $original = ChannelInitializationConfiguration::createWithDefaults();

        self::assertSame(['automaticChannelInitialization'], array_keys(get_object_vars($original)));
        self::assertCopyChangesOnly(
            $original,
            fn (ChannelInitializationConfiguration $configuration) => $configuration->withAutomaticChannelInitialization(false),
            ['automaticChannelInitialization' => false],
        );
    }

    private static function assertCopyChangesOnly(object $original, Closure $with, array $changedFields): void
    {
        $fieldsBefore = get_object_vars($original);

        $copy = $with($original);

        self::assertNotSame($original, $copy);
        self::assertSame($original::class, $copy::class);
        self::assertSame($fieldsBefore, get_object_vars($original));
        self::assertEquals(array_replace($fieldsBefore, $changedFields), get_object_vars($copy));
    }
}
