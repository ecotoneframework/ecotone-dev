<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\ClosureInAttribute;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresPhp;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\ClosurePartitionedHandler;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\ClosurePartitionedPlaceOrder;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhp('>= 8.5.0')]
final class PartitionDeadLetterClosureTest extends DbalMessagingTestCase
{
    public function test_the_partition_key_is_calculated_by_a_closure_over_the_command(): void
    {
        $handler = new ClosurePartitionedHandler();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [ClosurePartitionedHandler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: true),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('1', 'broken'));
        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('1', 'coffee'));
        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('2', 'tea'));
        $ecotone->run('closurePartition.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame(['2:tea'], $handler->placed);
        self::assertSame(['1', '1'], array_map(static fn (ErrorContext $errorContext): ?string => $errorContext->partitionKey, $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0)));
    }

    public function test_the_partition_key_is_calculated_by_a_closure_over_the_command_of_a_dbal_channel(): void
    {
        $handler = new ClosurePartitionedHandler();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [ClosurePartitionedHandler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                DbalBackedMessageChannelBuilder::create('closurePartition.orders'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();

        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('1', 'broken'));
        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('1', 'coffee'));
        $ecotone->sendCommand(new ClosurePartitionedPlaceOrder('2', 'tea'));
        $ecotone->run('closurePartition.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withHandledMessageLimit(10));

        self::assertSame(['2:tea'], $handler->placed);
        self::assertSame(['1', '1'], array_map(static fn (ErrorContext $errorContext): ?string => $errorContext->partitionKey, $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0)));
    }
}
