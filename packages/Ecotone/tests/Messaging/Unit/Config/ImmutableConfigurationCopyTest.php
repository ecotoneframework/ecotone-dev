<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Closure;
use Ecotone\Api\EventSourcing\FieldType;
use Ecotone\Api\EventSourcing\MetadataMatcher;
use Ecotone\Api\EventSourcing\Operator;
use Ecotone\Api\ExtensionObject\ChannelInitializationConfiguration;
use Ecotone\Api\Messaging\BatchMessage;
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

    public function test_metadata_matcher_copy_appends_only_the_new_match(): void
    {
        $original = MetadataMatcher::create([])->withMetadataMatch('_aggregate_id', Operator::EQUALS, '123');

        self::assertSame(['data'], array_keys(get_object_vars($original)));
        self::assertCopyChangesOnly(
            $original,
            fn (MetadataMatcher $matcher) => $matcher->withMetadataMatch('event_name', Operator::IN, ['created'], FieldType::MESSAGE_PROPERTY),
            ['data' => [
                ['field' => '_aggregate_id', 'operator' => Operator::EQUALS, 'value' => '123', 'fieldType' => FieldType::METADATA],
                ['field' => 'event_name', 'operator' => Operator::IN, 'value' => ['created'], 'fieldType' => FieldType::MESSAGE_PROPERTY],
            ]],
        );
    }

    public function test_batch_message_copy_appends_only_the_new_entry(): void
    {
        $original = BatchMessage::constructEmpty()->append('first', ['id' => '1']);

        self::assertSame(['entries'], array_keys(get_object_vars($original)));
        self::assertCopyChangesOnly(
            $original,
            fn (BatchMessage $batchMessage) => $batchMessage->append('second', ['id' => '2']),
            ['entries' => [
                ['payload' => 'first', 'headers' => ['id' => '1']],
                ['payload' => 'second', 'headers' => ['id' => '2']],
            ]],
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
