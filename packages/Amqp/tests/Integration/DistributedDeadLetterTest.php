<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Publisher\UserService;
use Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver\TicketServiceReceiver;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DistributedDeadLetterTest extends AmqpMessagingTest
{
    public function test_exception_handling_with_retry_dead_letter_when_using_distribution(): void
    {
        $userService = $this->bootstrapEcotone('user_service', ['Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Publisher'], [new UserService()]);
        $ticketService = $this->bootstrapEcotone('ticket_service', ['Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver'], [new TicketServiceReceiver()]);

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_ERROR_TICKETS_COUNT));

        $userService->sendCommandWithRoutingKey(UserService::CHANGE_BILLING_DETAILS, 'user_service');

        $ticketService->run('ticket_service');
        self::assertEquals(0, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_ERROR_TICKETS_COUNT));

        $ticketService->run('ticket_service');
        self::assertEquals(1, $ticketService->sendQueryWithRouting(TicketServiceReceiver::GET_ERROR_TICKETS_COUNT));
    }

    private function bootstrapEcotone(string $serviceName, array $namespaces, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory()], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withServiceName($serviceName)
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
