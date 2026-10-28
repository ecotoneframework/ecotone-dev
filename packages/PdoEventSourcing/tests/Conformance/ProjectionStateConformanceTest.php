<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Conformance;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProjectionStateConformanceTest extends EventSourcingMessagingTestCase
{
    public static function storages(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('storages')]
    public function test_a_projection_run_overtaken_by_a_nested_run_of_the_same_partition_is_refused_on_save(string $storage): void
    {
        $ecotone = $this->bootstrap($storage);

        try {
            $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Anna', 'alert'));
            self::fail('Expected a ConcurrencyException');
        } catch (ConcurrencyException $exception) {
            self::assertStringContainsString("Projection 'nested_run_projection' (partition: global) was loaded at version 0, but another run of the same partition saved it first.", $exception->getMessage());
        }
    }

    private function bootstrap(string $storage): FlowTestSupport
    {
        $projection = new #[Projection('nested_run_projection'), FromAggregateStream(Ticket::class)] class () {
            private bool $nestedRunSent = false;

            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event, #[Reference] CommandBus $commandBus): void
            {
                if ($this->nestedRunSent) {
                    return;
                }

                $this->nestedRunSent = true;
                $commandBus->send(new RegisterTicket('ticket-2', 'Ben', 'alert'));
            }
        };

        return $storage === 'in-memory'
            ? $this->bootstrapFlowTesting(
                classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
                containerOrAvailableServices: [$projection, new TicketEventConverter()],
                enabledModules: [],
            )
            : $this->bootstrapFlowTesting(
                classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
                containerOrAvailableServices: [$projection, new TicketEventConverter(), DbalConnectionFactory::class => self::getConnectionFactory()],
                enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            );
    }
}
