<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalIdWithDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\CloseTicket;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\CreateTicket;
use Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate\Ticket;

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
}
