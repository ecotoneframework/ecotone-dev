<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\EventRevision\Person;
use Test\Ecotone\Modelling\Fixture\EventRevision\RegisterPerson;

final class EventSourcingAggregateTest extends TestCase
{
    public function test_registering_and_using_headers_in_event_sourcing_handler(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting([
            Person::class,
        ], defaultEnterpriseMode: true);


        $ecotoneLite->sendCommand(new RegisterPerson('123', 'premium'));

        $person = $ecotoneLite->getAggregate(Person::class, '123');

        self::assertEquals('123', $person->getPersonId());
        self::assertEquals('premium', $person->getType());
        self::assertEquals(2, $person->getRegisteredWithRevision());
    }
}