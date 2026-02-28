<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler\EmailCommandHandler;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler\ExpressionDeduplicationCommandHandler;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler\TrackingNameDeduplicationCommandHandler;
use Test\Ecotone\Dbal\Fixture\DeduplicationEventHandler\DeduplicatedEventHandler;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DbalDeduplicationModuleTest extends DbalMessagingTestCase
{
    public function test_deduplicating_given_command_handler()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [EmailCommandHandler::class],
            [
                new EmailCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $messageId = Uuid::v7()->toRfc4122();
        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('email_event_handler.handle', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->sendCommandWithRoutingKey('email_event_handler.handle', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_after_first_handling_was_failure_during_asynchronous_processing()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [EmailCommandHandler::class],
            [
                new EmailCommandHandler(1),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create('email'),
                ])
        );

        $ecotoneLite->sendCommandWithRoutingKey('email_event_handler.handle', metadata: [MessageHeaders::MESSAGE_ID => Uuid::v7()->toRfc4122()]);

        $executionPollingMetadata = ExecutionPollingMetadata::createWithDefaults()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(100)
            ->withStopOnError(false);

        $this->assertEquals(
            2,
            $ecotoneLite
                ->run('email', $executionPollingMetadata)
                ->run('email', $executionPollingMetadata)
                ->run('email', $executionPollingMetadata)
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_given_event_handler_with_global_deduplication()
    {
        $queueName = 'async';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DeduplicatedEventHandler::class],
            [
                new DeduplicatedEventHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $messageId = Uuid::v7()->toRfc4122();
        $this->assertEquals(
            2,
            $ecotoneLite
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(4, 300))
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_given_event_handler_with_custom_timeout()
    {
        $queueName = 'async';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DeduplicatedEventHandler::class],
            [
                new DeduplicatedEventHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true, expirationTime: 60000),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $messageId = Uuid::v7()->toRfc4122();
        $this->assertEquals(
            2,
            $ecotoneLite
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2, 300))
                ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2, 300))
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_given_event_handler_with_custom_timeout_and_batch_size()
    {
        $queueName = 'async';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DeduplicatedEventHandler::class],
            [
                new DeduplicatedEventHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true, expirationTime: 1, removalBatchSize: 1),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $messageId = Uuid::v7()->toRfc4122();
        $this->assertEquals(
            4,
            $ecotoneLite
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2, 300))
                ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2, 300))
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_given_event_handler_with_custom_header()
    {
        $queueName = 'async';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [DeduplicatedEventHandler::class],
            [
                new DeduplicatedEventHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(false),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $messageId = Uuid::v7()->toRfc4122();
        $this->assertEquals(
            2,
            $ecotoneLite
                ->publishEventWithRoutingKey('order.was_placed', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->publishEventWithRoutingKey('order.was_placed', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(4, maxExecutionTimeInMilliseconds: 1000000))
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_with_custom_deduplication_header()
    {
        $queueName = Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [EmailCommandHandler::class],
            [
                new EmailCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($queueName)
                        ->withAutoDeclare(false),
                ])
        );

        $emailId = '123x';
        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('email_event_handler.handle_with_custom_deduplication_header', metadata: ['emailId' => $emailId])
                ->sendCommandWithRoutingKey('email_event_handler.handle_with_custom_deduplication_header', metadata: ['emailId' => $emailId])
                ->sendQueryWithRouting('email_event_handler.getCallCount')
        );
    }

    public function test_deduplicating_with_header_expression()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExpressionDeduplicationCommandHandler::class],
            [
                new ExpressionDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_header_expression', metadata: ['orderId' => 'order-123'])
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_header_expression', metadata: ['orderId' => 'order-123'])
                ->sendQueryWithRouting('expression_deduplication.getCallCount')
        );
    }

    public function test_deduplicating_with_payload_expression()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExpressionDeduplicationCommandHandler::class],
            [
                new ExpressionDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_payload_expression', 'unique-payload-1')
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_payload_expression', 'unique-payload-1')
                ->sendQueryWithRouting('expression_deduplication.getCallCount')
        );
    }

    public function test_deduplicating_with_complex_expression()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExpressionDeduplicationCommandHandler::class],
            [
                new ExpressionDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_complex_expression', 'order-data', metadata: ['customerId' => 'customer-123'])
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_complex_expression', 'order-data', metadata: ['customerId' => 'customer-123'])
                ->sendQueryWithRouting('expression_deduplication.getCallCount')
        );
    }

    public function test_deduplicating_with_expression_allows_different_values()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExpressionDeduplicationCommandHandler::class],
            [
                new ExpressionDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $this->assertEquals(
            2,
            $ecotoneLite
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_header_expression', metadata: ['orderId' => 'order-123'])
                ->sendCommandWithRoutingKey('expression_deduplication.handle_with_header_expression', metadata: ['orderId' => 'order-456'])
                ->sendQueryWithRouting('expression_deduplication.getCallCount')
        );
    }

    public function test_deduplicating_with_expression_in_asynchronous_processing()
    {
        $queueName = 'async_expression';
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExpressionDeduplicationCommandHandler::class],
            [
                new ExpressionDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommandWithRoutingKey('expression_deduplication.handle_async_with_expression', metadata: ['orderId' => 'order-123'])
                ->sendCommandWithRoutingKey('expression_deduplication.handle_async_with_expression', metadata: ['orderId' => 'order-123'])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(4, 300))
                ->sendQueryWithRouting('expression_deduplication.getCallCount')
        );
    }

    public function test_deduplicating_with_tracking_name_isolation()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TrackingNameDeduplicationCommandHandler::class],
            [
                new TrackingNameDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // Send same orderId to both tracking contexts
        $ecotoneLite
            ->sendCommandWithRoutingKey('tracking.handle_with_tracking_one', metadata: ['orderId' => 'order-123'])
            ->sendCommandWithRoutingKey('tracking.handle_with_tracking_two', metadata: ['orderId' => 'order-123']);

        // Both should be processed because they have different tracking names
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('tracking.getTrackingOneCallCount'));
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('tracking.getTrackingTwoCallCount'));
    }

    public function test_deduplicating_within_same_tracking_name()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TrackingNameDeduplicationCommandHandler::class],
            [
                new TrackingNameDeduplicationCommandHandler(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // Send same orderId twice to same tracking context
        $ecotoneLite
            ->sendCommandWithRoutingKey('tracking.handle_with_tracking_one', metadata: ['orderId' => 'order-456'])
            ->sendCommandWithRoutingKey('tracking.handle_with_tracking_one', metadata: ['orderId' => 'order-456']);

        // Only first should be processed due to deduplication within same tracking name
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('tracking.getTrackingOneCallCount'));
        $this->assertEquals(0, $ecotoneLite->sendQueryWithRouting('tracking.getTrackingTwoCallCount'));
    }
}
