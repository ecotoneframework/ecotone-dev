<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Lite\EcotoneLite;
use Error;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalId;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalIdWithDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\CloseTicket;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\CreateTicket;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\Ticket;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregateWithoutDiscriminator\CloseTicket as CloseTicketWithoutDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregateWithoutDiscriminator\CreateTicket as CreateTicketWithoutDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregateWithoutDiscriminator\Ticket as TicketWithoutDiscriminator;

/**
 * licence Apache-2.0
 * @internal
 */
final class UnionIdentifierAggregateTest extends TestCase
{
    public function test_union_type_identifier_works_for_event_sourced_aggregates_when_using_union_discriminator(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class],
        );

        $ecotoneLite->sendCommand(new CreateTicket(new InternalIdWithDiscriminator('123')));

        $this->assertTrue(
            $ecotoneLite
                ->sendCommand(new CloseTicket('123'))
                ->getAggregate(Ticket::class, '123')
                ->isClosed()
        );
    }

    public function test_union_type_identifier_is_not_supported_for_event_sourced_aggregates_without_union_discriminator(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [TicketWithoutDiscriminator::class],
        );

        $this->expectException(Error::class);

        $ecotoneLite->sendCommand(new CreateTicketWithoutDiscriminator(new InternalId('123')));

        $ecotoneLite
            ->sendCommand(new CloseTicketWithoutDiscriminator('123'))
            ->getAggregate(TicketWithoutDiscriminator::class, '123')
            ->isClosed();
    }
}
