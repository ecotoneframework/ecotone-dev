<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TestingSetupOnPinnedClockTest extends DbalMessagingTestCase
{
    public function test_a_run_of_an_idle_dbal_channel_stops_at_its_execution_time_limit_on_the_pinned_clock(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['stopped at 00:00:00.200'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_a_run_handles_the_waiting_message_and_stops_at_its_execution_time_limit_on_the_pinned_clock(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->sendCommandWithRouting('register', 'first');

        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame(['first', 'stopped at 00:00:00.101'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    private function bootstrap(): FlowTestSupport
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
            [$consumer, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('async')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            initialTime: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }
}
