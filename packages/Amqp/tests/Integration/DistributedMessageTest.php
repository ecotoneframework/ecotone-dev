<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Publisher\UserService;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver\TicketServiceReceiver;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DistributedMessageTest extends AmqpMessagingTestCase
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
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([...$this->getConnectionFactoryReferences()], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withServiceName($serviceName)
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE,])
                ->withNamespaces($namespaces)
                ->withLicenceKey(LicenceTesting::VALID_LICENCE),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
