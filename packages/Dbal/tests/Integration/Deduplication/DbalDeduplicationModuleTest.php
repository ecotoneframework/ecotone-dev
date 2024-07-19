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
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler\EmailCommandHandler;
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

        $messageId = Uuid::uuid4()->toString();
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

        $ecotoneLite->sendCommandWithRoutingKey('email_event_handler.handle', metadata: [MessageHeaders::MESSAGE_ID => Uuid::uuid4()->toString()]);

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

        $messageId = Uuid::uuid4()->toString();
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
                    DbalConfiguration::createWithDefaults()->withDeduplication(true, minimumTimeToRemoveMessageInMilliseconds: 60000),
                    DbalBackedMessageChannelBuilder::create($queueName),
                ])
        );

        $messageId = Uuid::uuid4()->toString();
        $this->assertEquals(
            2,
            $ecotoneLite
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->publishEventWithRoutingKey('order.was_cancelled', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
                ->run($queueName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(4, 300))
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

        $messageId = Uuid::uuid4()->toString();
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
        $queueName = Uuid::uuid4()->toString();

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
}
