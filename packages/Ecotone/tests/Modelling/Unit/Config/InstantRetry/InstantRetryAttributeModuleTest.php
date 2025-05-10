<?php

declare(strict_types=1);

namespace Modelling\Unit\Config\InstantRetry;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Modelling\Fixture\Retry\CommandBusWithCustomRetryCountAttribute;
use Test\Ecotone\Modelling\Fixture\Retry\CommandBusWithInstantRetryAttribute;
use Test\Ecotone\Modelling\Fixture\Retry\CommandBusWithInvalidArgumentExceptionsAttribute;
use Test\Ecotone\Modelling\Fixture\Retry\CommandBusWithRuntimeExceptionsAttribute;
use Test\Ecotone\Modelling\Fixture\Retry\RetriedCommandHandler;

/**
 * licence Enterprise
 */
final class InstantRetryAttributeModuleTest extends TestCase
{
    public function test_retrying_with_asynchronous_handler_and_ignoring_retries_on_nested_command_bus()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async'),
                    InstantRetryConfiguration::createWithDefaults()
                        ->withAsynchronousEndpointsRetry(true, 3)
                        ->withCommandBusRetry(true, 3),
                ])
        );

        $this->assertEquals(
            4,
            $ecotoneLite
                ->sendCommandWithRoutingKey('retried.nested.async', 4)
                ->run('async', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup())
                ->sendQueryWithRouting('retried.getCallCount')
        );
    }

    public function test_exceeding_retries_with_asynchronous_handler()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async'),
                    InstantRetryConfiguration::createWithDefaults()
                        ->withAsynchronousEndpointsRetry(true, 2),
                ])
        );

        $this->expectException(RuntimeException::class);

        $ecotoneLite
            ->sendCommandWithRoutingKey('retried.asynchronous', 4)
            ->run('async', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
    }

    public function test_retrying_with_command_bus_using_attribute()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithInstantRetryAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
        );

        $commandBus = $ecotoneLite->getGateway(CommandBusWithInstantRetryAttribute::class);
        $commandBus->sendWithRouting('retried.synchronous', 4);

        $this->assertEquals(
            4,
            $ecotoneLite->sendQueryWithRouting('retried.getCallCount')
        );
    }

    public function test_exceeding_retries_with_command_bus_using_attribute()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithCustomRetryCountAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
        );

        $this->expectException(RuntimeException::class);

        $ecotoneLite
            ->getGateway(CommandBusWithCustomRetryCountAttribute::class)
            ->sendWithRouting('retried.synchronous', 4);
    }

    public function test_retrying_with_command_bus_using_attribute_for_specific_exceptions_which_is_not_thrown()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithInvalidArgumentExceptionsAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
        );

        $exceptionThrown = false;
        try {
            $commandBus = $ecotoneLite->getGateway(CommandBusWithInvalidArgumentExceptionsAttribute::class);
            $commandBus->sendWithRouting('retried.synchronous', 2);
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
        }

        if (! $exceptionThrown) {
            $this->fail('RuntimeException was not thrown');
        }

        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('retried.getCallCount'));
    }

    public function test_retrying_with_command_bus_using_attribute_for_specific_exceptions_which_is_thrown()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithRuntimeExceptionsAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
        );

        $commandBus = $ecotoneLite->getGateway(CommandBusWithRuntimeExceptionsAttribute::class);
        $commandBus->sendWithRouting('retried.synchronous', 2);

        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('retried.getCallCount'));
    }

    public function test_attribute_configuration_takes_precedence_over_global_configuration_with_success()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithCustomRetryCountAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    InstantRetryConfiguration::createWithDefaults()
                        ->withCommandBusRetry(true, 3),
                ])
        );

        $ecotoneLite
            ->getGateway(CommandBusWithCustomRetryCountAttribute::class)
            ->sendWithRouting('retried.synchronous', 3);

        $this->assertEquals(3, $ecotoneLite->sendQueryWithRouting('retried.getCallCount'));
    }

    public function test_attribute_configuration_takes_precedence_over_global_configuration_with_failure()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [RetriedCommandHandler::class, CommandBusWithCustomRetryCountAttribute::class],
            [
                new RetriedCommandHandler(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    InstantRetryConfiguration::createWithDefaults()
                        ->withCommandBusRetry(true, 3),
                ])
        );

        $this->expectException(RuntimeException::class);

        $ecotoneLite
            ->getGateway(CommandBusWithCustomRetryCountAttribute::class)
            ->sendWithRouting('retried.synchronous', 4);
    }
}
