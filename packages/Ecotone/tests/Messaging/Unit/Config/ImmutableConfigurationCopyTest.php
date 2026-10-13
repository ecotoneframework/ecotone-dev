<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Closure;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\EventSourcing\FieldType;
use Ecotone\Api\EventSourcing\MetadataMatcher;
use Ecotone\Api\EventSourcing\Operator;
use Ecotone\Api\ExtensionObject\ChannelInitializationConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Messaging\BatchMessage;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
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

    public function test_distributed_service_map_copy_changes_only_the_named_mapping(): void
    {
        $original = DistributedServiceMap::initialize('billing')
            ->withCommandMapping('orders', 'orders_channel')
            ->withCommandMapping('42', 'numbered_channel')
            ->withEventMapping('audit_channel', ['order.*'])
            ->withAsynchronousChannel('async');
        $observe = fn (DistributedServiceMap $map) => [
            ...get_object_vars($map),
            'referenceName' => $map->getReferenceName(),
            'channelsForOrderPlaced' => $map->getAllSubscriptionChannels('shop', 'order.placed'),
            'channelsForInvoiceIssued' => $map->getAllSubscriptionChannels('shop', 'invoice.issued'),
        ];

        self::assertSame(['commandMapping', 'distributedBusAnnotations'], array_keys(get_object_vars($original)));
        self::assertCopyChangesOnly(
            $original,
            fn (DistributedServiceMap $map) => $map->withCommandMapping('payments', 'payments_channel'),
            ['commandMapping' => ['orders' => 'orders_channel', '42' => 'numbered_channel', 'payments' => 'payments_channel']],
            $observe,
        );
        self::assertCopyChangesOnly(
            $original,
            fn (DistributedServiceMap $map) => $map->withEventMapping('invoices_channel', ['invoice.*']),
            ['channelsForInvoiceIssued' => ['invoices_channel']],
            $observe,
        );
        self::assertCopyChangesOnly(
            $original,
            fn (DistributedServiceMap $map) => $map->withAsynchronousChannel('second_async'),
            ['distributedBusAnnotations' => [new AttributeDefinition(Asynchronous::class, ['async']), new AttributeDefinition(Asynchronous::class, ['second_async'])]],
            $observe,
        );
    }

    public function test_test_configuration_copy_changes_only_the_named_fields(): void
    {
        $original = TestConfiguration::createWithDefaults()
            ->withFailOnCommandHandlerNotFound(false)
            ->withFailOnQueryHandlerNotFound(false)
            ->withMediaTypeConversion('orders', MediaType::createApplicationJson())
            ->withSpyOnChannel('audit')
            ->withInMemoryConsumerPositionTracker(false)
            ->withDatabaseTablesSetUpOnBootstrap(false);

        self::assertSame(
            ['failOnCommandHandlerNotFound', 'failOnQueryHandlerNotFound', 'pollableChannelMediaTypeConversion', 'channelToConvertOn', 'spiedChannelNames', 'inMemoryConsumerPositionTracker', 'databaseTablesSetUpOnBootstrap'],
            array_keys(get_object_vars($original)),
        );
        self::assertCopyChangesOnly($original, fn (TestConfiguration $configuration) => $configuration->withFailOnCommandHandlerNotFound(true), ['failOnCommandHandlerNotFound' => true]);
        self::assertCopyChangesOnly($original, fn (TestConfiguration $configuration) => $configuration->withFailOnQueryHandlerNotFound(true), ['failOnQueryHandlerNotFound' => true]);
        self::assertCopyChangesOnly(
            $original,
            fn (TestConfiguration $configuration) => $configuration->withMediaTypeConversion('payments', MediaType::createApplicationXml()),
            ['pollableChannelMediaTypeConversion' => MediaType::createApplicationXml(), 'channelToConvertOn' => 'payments', 'spiedChannelNames' => ['orders', 'audit', 'payments']],
        );
        self::assertCopyChangesOnly($original, fn (TestConfiguration $configuration) => $configuration->withSpyOnChannel('shipping'), ['spiedChannelNames' => ['orders', 'audit', 'shipping']]);
        self::assertCopyChangesOnly($original, fn (TestConfiguration $configuration) => $configuration->withInMemoryConsumerPositionTracker(true), ['inMemoryConsumerPositionTracker' => true]);
        self::assertCopyChangesOnly($original, fn (TestConfiguration $configuration) => $configuration->withDatabaseTablesSetUpOnBootstrap(true), ['databaseTablesSetUpOnBootstrap' => true]);
    }

    public function test_execution_polling_metadata_copy_changes_only_the_named_fields(): void
    {
        $original = ExecutionPollingMetadata::createWithDefaults()
            ->withCron('* * * * *')
            ->withHandledMessageLimit(10)
            ->withMemoryLimitInMegabytes(256)
            ->withExecutionTimeLimitInMilliseconds(1000)
            ->withStopOnError(false)
            ->withFinishWhenNoMessages(false)
            ->withExecutionAmountLimit(5);

        self::assertSame(
            ['handledMessageLimit', 'executionTimeLimitInMilliseconds', 'memoryLimitInMegabytes', 'cron', 'stopOnError', 'finishWhenNoMessages', 'executionAmountLimit'],
            array_keys(get_object_vars($original)),
        );
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withCron('0 * * * *'), ['cron' => '0 * * * *']);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withHandledMessageLimit(20), ['handledMessageLimit' => 20]);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withMemoryLimitInMegabytes(512), ['memoryLimitInMegabytes' => 512]);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withExecutionTimeLimitInMilliseconds(0), ['executionTimeLimitInMilliseconds' => 0]);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withStopOnError(true), ['stopOnError' => true]);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withFinishWhenNoMessages(true), ['finishWhenNoMessages' => true]);
        self::assertCopyChangesOnly($original, fn (ExecutionPollingMetadata $metadata) => $metadata->withExecutionAmountLimit(7), ['executionAmountLimit' => 7]);
        self::assertCopyChangesOnly(
            $original,
            fn (ExecutionPollingMetadata $metadata) => $metadata->withTestingSetup(handledMessageLimit: 3, executionTimeLimitInMilliseconds: 30, stopOnError: true),
            ['handledMessageLimit' => 3, 'executionTimeLimitInMilliseconds' => 30, 'stopOnError' => true],
        );
    }

    private static function assertCopyChangesOnly(object $original, Closure $with, array $changedFields, ?Closure $observe = null): void
    {
        $observe ??= fn (object $object): array => get_object_vars($object);
        $fieldsBefore = $observe($original);

        $copy = $with($original);

        self::assertNotSame($original, $copy);
        self::assertSame($original::class, $copy::class);
        self::assertEquals($fieldsBefore, $observe($original));
        self::assertEquals(array_replace($fieldsBefore, $changedFields), $observe($copy));
    }
}
