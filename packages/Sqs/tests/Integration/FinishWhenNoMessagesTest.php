<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FinishWhenNoMessagesTest extends ConnectionTestCase
{
    public function test_a_run_of_an_idle_queue_stops_without_waiting_for_the_receive_timeout(): void
    {
        $ecotone = $this->bootstrapWithReceiveTimeoutOfOneMinute();

        $startedAt = hrtime(true);
        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
        $this->assertLessThan(5.0, (hrtime(true) - $startedAt) / 1e9);
    }

    public function test_a_run_handles_the_waiting_message_and_stops_without_waiting_for_the_receive_timeout(): void
    {
        $ecotone = $this->bootstrapWithReceiveTimeoutOfOneMinute();
        $ecotone->sendCommandWithRouting('register', 'first');

        $startedAt = hrtime(true);
        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['first', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
        $this->assertLessThan(5.0, (hrtime(true) - $startedAt) / 1e9);
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

        return EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [$consumer, SqsConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [SqsBackedMessageChannelBuilder::create('async')->withReceiveTimeout(60_000)],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            initialTime: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }
}
