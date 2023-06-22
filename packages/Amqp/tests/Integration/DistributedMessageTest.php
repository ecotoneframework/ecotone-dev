<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Publisher\UserService;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver\TicketServiceReceiver;

/**
 * @internal
 */
final class DistributedMessageTest extends AmqpMessagingTest
{
    public function test_distributing_message_to_another_service(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedMessage\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone('ticket_service', ['Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver'], [new TicketServiceReceiver()]);

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(UserService::CHANGE_BILLING_DETAILS, 'user_service');
        $ticketService->run('ticket_service');
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_TICKETS_COUNT));
    }

    private function bootstrapEcotone(string $serviceName, array $namespaces, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory()], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withServiceName($serviceName)
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
