<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class DecisionModelArrayTagTest extends TestCase
{
    public function test_a_model_scoped_by_one_value_of_an_array_valued_event_tag_sees_that_event(): void
    {
        $handler = new ReserveSeatHandlerForArrayTagTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, SeatReservationForArrayTagTest::class, SeatsReservedForArrayTagTest::class],
            containerOrAvailableServices: [$handler],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->withEvents([new SeatsReservedForArrayTagTest(['seat-1', 'seat-2'])]);

        $ecotone->sendCommand(new ReserveSeatForArrayTagTest('seat-1'));

        $this->assertTrue(ReserveSeatHandlerForArrayTagTest::$observedReserved);

        $ecotone->sendCommand(new ReserveSeatForArrayTagTest('seat-3'));

        $this->assertFalse(ReserveSeatHandlerForArrayTagTest::$observedReserved);
    }
}

final readonly class ReserveSeatForArrayTagTest
{
    public function __construct(
        public string $seatId,
    ) {
    }
}

final readonly class SeatsReservedForArrayTagTest
{
    /**
     * @param string[] $seatIds
     */
    public function __construct(
        #[EventTag('seat')] public array $seatIds,
    ) {
    }
}

#[DecisionModel]
final class SeatReservationForArrayTagTest
{
    private bool $reserved = false;

    #[EventSourcingHandler]
    public function reserved(SeatsReservedForArrayTagTest $event): void
    {
        $this->reserved = true;
    }

    public function isReserved(): bool
    {
        return $this->reserved;
    }
}

final class ReserveSeatHandlerForArrayTagTest
{
    public static bool $observedReserved = false;

    #[CommandHandler]
    public function reserve(ReserveSeatForArrayTagTest $command, SeatReservationForArrayTagTest $seat): array
    {
        self::$observedReserved = $seat->isReserved();

        return [];
    }
}
