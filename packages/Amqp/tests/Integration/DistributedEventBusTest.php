<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler\TicketNotificationSubscriber;
use Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher\UserService;
use Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver\TicketServiceReceiver;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 */
final class DistributedEventBusTest extends AmqpMessagingTest
{
    public function test_distributing_event_to_another_service(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone('ticket_service', ['Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver'], [new TicketServiceReceiver()]);

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(UserService::CHANGE_BILLING_DETAILS, 'user_service');

        $ticketService->run('ticket_service');
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));
    }

    public function test_distributing_event_and_publish_async_private_event(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone(
            'ticket_service',
            [
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver',
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler',
            ],
            [new TicketServiceReceiver(), new TicketNotificationSubscriber()]
        );

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(UserService::CHANGE_BILLING_DETAILS, 'user_service');

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
        $ticketService->run('notification_channel');
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
    }

    public function test_distributing_event_and_publish_async_without_amqp_transactions(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone(
            'ticket_service',
            [
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver',
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler',
            ],
            [new TicketServiceReceiver(), new TicketNotificationSubscriber()]
        );

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(
            UserService::CHANGE_BILLING_DETAILS,
            'user_service',
            metadata: [
                'shouldThrowException' => true,
            ]
        );

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
        $ticketService->run('notification_channel');
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
    }

    public function test_distributing_event_and_publish_async_with_amqp_transactions(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedEventBus\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone(
            'ticket_service',
            [
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver',
                'Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler',
            ],
            [new TicketServiceReceiver(), new TicketNotificationSubscriber()],
            [
                AmqpConfiguration::createWithDefaults()
                    ->withTransactionOnAsynchronousEndpoints(true),
            ]
        );

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(
            UserService::CHANGE_BILLING_DETAILS,
            'user_service',
            metadata: [
                'shouldThrowException' => true,
            ]
        );

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
        $ticketService->run('notification_channel');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketNotificationSubscriber::GET_TICKET_NOTIFICATION_COUNT));
    }

    private function bootstrapEcotone(
        string $serviceName,
        array  $namespaces,
        array  $services,
        array  $extensionObjects = [],
    ): FlowTestSupport {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory()], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withServiceName($serviceName)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::AMQP_PACKAGE]))
                ->withNamespaces($namespaces)
                ->withExtensionObjects($extensionObjects),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
