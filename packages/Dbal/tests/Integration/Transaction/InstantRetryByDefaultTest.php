<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class InstantRetryByDefaultTest extends DbalMessagingTestCase
{
    public function test_a_command_is_retried_by_default_when_the_command_bus_runs_in_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults());

        $ecotone->sendCommandWithRouting('flaky');

        self::assertSame(3, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_a_class_routed_command_is_retried_by_default_when_the_command_bus_runs_in_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults());

        $ecotone->sendCommand(new FlakyCommandForRetryByDefault());

        self::assertSame(3, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_a_command_is_not_retried_by_default_when_the_command_bus_runs_without_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false));

        $this->assertFailsAfterOneAttempt($ecotone, fn () => $ecotone->sendCommandWithRouting('flaky'));
    }

    public function test_a_routed_command_whose_handler_runs_without_a_database_transaction_is_not_retried_by_default(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults());

        $this->assertFailsAfterOneAttempt($ecotone, fn () => $ecotone->sendCommandWithRouting('flaky.withoutTransaction'));
    }

    public function test_a_class_routed_command_whose_handler_runs_without_a_database_transaction_is_not_retried_by_default(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults());

        $this->assertFailsAfterOneAttempt($ecotone, fn () => $ecotone->sendCommand(new FlakyCommandWithoutTransactionForRetryByDefault()));
    }

    public function test_an_asynchronous_endpoint_is_retried_by_default_when_it_runs_in_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false));

        $ecotone->sendCommandWithRouting('flaky.async');
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(3, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_an_asynchronous_endpoint_is_not_retried_by_default_when_it_runs_without_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false));

        $ecotone->sendCommandWithRouting('flaky.async');
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(1, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_an_asynchronous_endpoint_excluded_from_database_transactions_is_not_retried_by_default(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withoutTransactionOnAsynchronousEndpoints(['async']));

        $ecotone->sendCommandWithRouting('flaky.async');
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(1, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_an_explicitly_disabled_command_bus_retry_wins_over_the_database_transaction_default(): void
    {
        $ecotone = $this->bootstrap(
            DbalConfiguration::createWithDefaults(),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        );

        $this->assertFailsAfterOneAttempt($ecotone, fn () => $ecotone->sendCommandWithRouting('flaky'));
    }

    public function test_an_explicitly_enabled_command_bus_retry_runs_without_a_database_transaction(): void
    {
        $ecotone = $this->bootstrap(
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3),
        );

        $ecotone->sendCommandWithRouting('flaky');

        self::assertSame(3, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_an_explicitly_disabled_asynchronous_retry_wins_over_the_database_transaction_default(): void
    {
        $ecotone = $this->bootstrap(
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
        );

        $ecotone->sendCommandWithRouting('flaky.async');
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(1, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    public function test_a_flow_test_whose_test_configuration_switches_the_default_off_does_not_retry(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults(), defaultInstantRetries: false);

        $this->assertFailsAfterOneAttempt($ecotone, fn () => $ecotone->sendCommandWithRouting('flaky'));
    }

    public function test_a_flow_test_whose_test_configuration_switches_the_default_off_still_applies_an_explicit_instant_retry_configuration(): void
    {
        $ecotone = $this->bootstrap(
            DbalConfiguration::createWithDefaults(),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3),
            defaultInstantRetries: false,
        );

        $ecotone->sendCommandWithRouting('flaky');

        self::assertSame(3, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    private function assertFailsAfterOneAttempt(FlowTestSupport $ecotone, callable $send): void
    {
        try {
            $send();
            self::fail('Expected the flaky handler to fail');
        } catch (RuntimeException $exception) {
            self::assertSame('flaky failure', $exception->getMessage());
        }

        self::assertSame(1, $ecotone->sendQueryWithRouting('flaky.attempts'));
    }

    private function bootstrap(DbalConfiguration $dbalConfiguration, ?InstantRetryConfiguration $instantRetryConfiguration = null, bool $defaultInstantRetries = true): FlowTestSupport
    {
        $handlers = new class () {
            public int $attempts = 0;

            #[CommandHandler('flaky')]
            public function flaky(): void
            {
                $this->failTwice();
            }

            #[CommandHandler]
            public function flakyByClass(FlakyCommandForRetryByDefault $command): void
            {
                $this->failTwice();
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('flaky.withoutTransaction')]
            public function flakyWithoutTransaction(): void
            {
                $this->failTwice();
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler]
            public function flakyByClassWithoutTransaction(FlakyCommandWithoutTransactionForRetryByDefault $command): void
            {
                $this->failTwice();
            }

            #[Asynchronous('async')]
            #[CommandHandler('flaky.async', endpointId: 'flaky.async.endpoint')]
            public function flakyAsynchronous(): void
            {
                $this->failTwice();
            }

            #[QueryHandler('flaky.attempts')]
            public function attempts(): int
            {
                return $this->attempts;
            }

            private function failTwice(): void
            {
                $this->attempts++;
                if ($this->attempts < 3) {
                    throw new RuntimeException('flaky failure');
                }
            }
        };

        return $this->bootstrapFlowTesting(
            [$handlers::class],
            [$handlers, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: array_filter([
                $dbalConfiguration,
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                $instantRetryConfiguration,
            ]),
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries($defaultInstantRetries),
        );
    }
}

final class FlakyCommandForRetryByDefault
{
}

final class FlakyCommandWithoutTransactionForRetryByDefault
{
}
