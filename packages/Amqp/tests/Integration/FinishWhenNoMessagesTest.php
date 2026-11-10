<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use DateTimeImmutable;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FinishWhenNoMessagesTest extends AmqpMessagingTestCase
{
    public function test_a_run_of_an_idle_queue_stops_without_waiting_for_the_receive_timeout(): void
    {
        $ecotone = $this->bootstrapWithReceiveTimeoutOfOneMinute();

        $startedAt = hrtime(true);
        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $secondsTaken = (hrtime(true) - $startedAt) / 1e9;

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
        $this->assertLessThan(5.0, $secondsTaken);
    }

    public function test_a_console_run_of_an_idle_queue_stops_without_waiting_for_the_receive_timeout_within_its_execution_time_limit(): void
    {
        $ecotone = $this->bootstrapWithReceiveTimeoutOfOneMinute();

        $startedAt = hrtime(true);
        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 30000]);
        $secondsTaken = (hrtime(true) - $startedAt) / 1e9;

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
        $this->assertLessThan(5.0, $secondsTaken);
    }

    public function test_a_run_stops_without_waiting_for_the_receive_timeout_once_the_queue_is_drained(): void
    {
        $ecotone = $this->bootstrapWithReceiveTimeoutOfOneMinute();
        $ecotone->sendCommandWithRouting('register', 'first');

        $startedAt = hrtime(true);
        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 30000]);
        $secondsTaken = (hrtime(true) - $startedAt) / 1e9;

        $this->assertSame(['first', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
        $this->assertLessThan(5.0, $secondsTaken);
    }

    private function bootstrapWithReceiveTimeoutOfOneMinute(): FlowTestSupport
    {
        $consumer = new class () {
            private array $log = [];

            #[Asynchronous('async')]
            #[CommandHandler('register', endpointId: 'register.endpoint')]
            public function register(string $name): void
            {
                $this->log[] = $name;
            }

            #[OnConsumerStop]
            public function stopped(string $endpointId, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->log[] = 'stopped at ' . $clock->now()->format('H:i:s.v');
            }

            #[QueryHandler('consumer.log')]
            public function log(): array
            {
                return $this->log;
            }
        };

        return $this->bootstrapFlowTesting(
            [$consumer::class],
            [$consumer, ...$this->getConnectionFactoryReferences()],
            extensionObjects: [AmqpBackedMessageChannelBuilder::create('async')->withReceiveTimeout(60_000)],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-01-01 00:00:00')),
        );
    }
}
