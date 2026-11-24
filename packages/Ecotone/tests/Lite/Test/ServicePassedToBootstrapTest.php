<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Test;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ServicePassedToBootstrapTest extends TestCase
{
    public function test_handler_passed_as_a_service_is_registered_without_listing_its_class(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [$this->ticketDesk()],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('ticket.open', 'printer is on fire');

        $this->assertSame(['printer is on fire'], $ecotone->sendQueryWithRouting('ticket.list'));
    }

    public function test_handler_passed_as_a_service_under_its_own_class_name_is_registered_without_listing_its_class(): void
    {
        $ticketDesk = $this->ticketDesk();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [$ticketDesk::class => $ticketDesk],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('ticket.open', 'printer is on fire');

        $this->assertSame(['printer is on fire'], $ecotone->sendQueryWithRouting('ticket.list'));
    }

    public function test_handler_passed_as_a_service_is_registered_outside_of_flow_testing(): void
    {
        $ecotone = EcotoneLite::bootstrap(
            containerOrAvailableServices: [$this->ticketDesk()],
            enabledModules: [],
        );

        $ecotone->getCommandBus()->sendWithRouting('ticket.open', 'printer is on fire');

        $this->assertSame(['printer is on fire'], $ecotone->getQueryBus()->sendWithRouting('ticket.list'));
    }

    public function test_service_passed_under_another_name_is_only_a_dependency(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: ['ticketDesk' => $this->ticketDesk()],
            enabledModules: [],
        );

        $this->expectException(DestinationResolutionException::class);

        $ecotone->sendCommandWithRouting('ticket.open', 'printer is on fire');
    }

    private function ticketDesk(): object
    {
        return new class () {
            private array $opened = [];

            #[CommandHandler('ticket.open')]
            public function open(string $description): void
            {
                $this->opened[] = $description;
            }

            #[QueryHandler('ticket.list')]
            public function list(): array
            {
                return $this->opened;
            }
        };
    }
}
