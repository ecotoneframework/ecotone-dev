<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Modelling\DistributedBus;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Publisher\UserService;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver\TicketServiceMessagingConfiguration;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver\TicketServiceReceiver;
use Test\Ecotone\Amqp\Fixture\DistributedCommandBus\ReceiverEventHandler\TicketNotificationEventHandler;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DistributedCommandBusTest extends AmqpMessagingTest
{
    public function test_distributing_command_to_another_service(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone('ticket_service', ['Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver'], [new TicketServiceReceiver(),
            //            'logger' => new EchoLogger(),
        ]);

        $ticketService->run('ticket_service', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 500));
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(UserService::CHANGE_BILLING_DETAILS, 'user_service');

        $ticketService->run('ticket_service', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 500));
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));
    }

    public function test_distributing_command_misses_heartbeat_and_reconnects(): void
    {
        $executionPollingMetadata = ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(10000)->withStopOnError(false);
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Publisher'], [new UserService()], amqpConfig: ['heartbeat' => 1]);
        $ticketService = $this->bootstrapEcotone('ticket_service', ['Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver', 'Test\Ecotone\Amqp\Fixture\DistributedCommandBus\ReceiverEventHandler'], [new TicketServiceReceiver([0, 6, 0]), new TicketNotificationEventHandler([0, 6, 0]),
            //            'logger' => new EchoLogger(),
        ], amqpConfig: ['heartbeat' => 1]);

        $ticketService->run('ticket_service', $executionPollingMetadata);
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $distributedBus = $userService->getGateway(DistributedBus::class);
        for ($i = 1; $i <= 3; $i++) {
            $distributedBus->sendCommand(
                TicketServiceMessagingConfiguration::SERVICE_NAME,
                TicketServiceReceiver::CREATE_TICKET_WITH_EVENT_ENDPOINT,
                'User changed billing address',
            );
        }

        $ticketService->run('ticket_service', $executionPollingMetadata);
        // Message may fail on acknowledgment, but it will be redelivered
        self::assertGreaterThanOrEqual(3, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $ticketService->run('async', $executionPollingMetadata);
        // When message failed on acknowledgment, it will re-publish on re-deliver
        self::assertGreaterThanOrEqual(3, $ticketService->sendQueryWithRouting(TicketNotificationEventHandler::GET_TICKETS_NOTIFICATION_COUNT));
    }

    private function bootstrapEcotone(string $serviceName, array $namespaces, array $services, array $amqpConfig = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory($amqpConfig)], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withServiceName($serviceName)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
