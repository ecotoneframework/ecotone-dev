<?php

declare(strict_types=1);

namespace App\Catalogue;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Modelling\WithEvents;
use Symfony\Component\Uid\Uuid;

#[Aggregate]
#[AggregateType('concert')]
#[Entity]
#[Table(name: 'concerts')]
final class Concert
{
    use WithEvents;

    #[Version]
    #[Column(type: 'integer')]
    private int $version = 1;

    /**
     * @param list<array{seatId: string, seatCode: string, tier: string}> $seats
     */
    private function __construct(
        #[Identifier]
        #[Id]
        #[Column(type: 'guid')]
        private string $concertId,
        #[Column(type: 'guid')]
        private string $venueId,
        #[Column(type: 'string')]
        private string $venueName,
        #[Column(type: 'string')]
        private string $artist,
        #[Column(type: 'datetimetz_immutable')]
        private DateTimeImmutable $startsAt,
        #[Column(type: 'integer')]
        private int $standardPriceCents,
        #[Column(type: 'integer')]
        private int $vipPriceCents,
        #[Column(type: 'string', length: 3)]
        private string $currency,
        #[Column(type: 'string', enumType: ConcertStatus::class)]
        private ConcertStatus $status,
        #[Column(type: 'json')]
        private array $seats,
    ) {
    }

    #[CommandHandler]
    public static function schedule(
        ScheduleConcert $command,
        #[Fetch(static function (#[Payload] ScheduleConcert $command): string {
            return $command->venueId;
        })] ?Venue $venue,
        EcotoneClockInterface $clock,
    ): self {
        if ($venue === null) {
            throw CatalogueRefusal::because(sprintf('A concert cannot be scheduled at venue %s, because no such venue exists. Pick one of the venues listed under /admin/venues, or create the venue first.', $command->venueId));
        }
        $artist = trim($command->artist);
        $now = $clock->now();
        if ($artist === '') {
            throw CatalogueRefusal::because(sprintf('A concert at %s needs an artist. Name the artist and schedule the concert again.', $venue->name()));
        }
        if ($command->startsAt <= $now) {
            throw CatalogueRefusal::because(sprintf('The concert of %s cannot be scheduled for %s, because that is in the past: it is now %s. Pick a start time after now.', $artist, $command->startsAt->format(DateTimeInterface::ATOM), $now->format(DateTimeInterface::ATOM)));
        }
        self::assertPrice($artist, Tier::STANDARD, $command->standardPriceCents);
        self::assertPrice($artist, Tier::VIP, $command->vipPriceCents);
        $currency = strtoupper(trim($command->currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw CatalogueRefusal::because(sprintf('The concert of %s cannot be priced in "%s", because a currency is a three-letter ISO 4217 code. Use a code like EUR, USD or GBP.', $artist, $command->currency));
        }

        $concertId = Uuid::v7()->toRfc4122();
        $concert = new self(
            concertId: $concertId,
            venueId: $command->venueId,
            venueName: $venue->name(),
            artist: $artist,
            startsAt: $command->startsAt,
            standardPriceCents: $command->standardPriceCents,
            vipPriceCents: $command->vipPriceCents,
            currency: $currency,
            status: ConcertStatus::SCHEDULED,
            seats: $venue->seatsFor($concertId),
        );
        $concert->recordThat(new ConcertScheduled(
            concertId: $concertId,
            venueName: $concert->venueName,
            artist: $artist,
            startsAt: $command->startsAt,
            currency: $currency,
            standardPriceCents: $command->standardPriceCents,
            vipPriceCents: $command->vipPriceCents,
            seats: array_map(static fn (array $seat): Seat => new Seat(seatId: $seat['seatId'], seatCode: $seat['seatCode'], tier: Tier::from($seat['tier'])), $concert->seats),
        ));

        return $concert;
    }

    #[CommandHandler]
    public function openSales(OpenSales $command): void
    {
        if ($this->status !== ConcertStatus::SCHEDULED) {
            throw CatalogueRefusal::because(match ($this->status) {
                ConcertStatus::ON_SALE => sprintf('Sales of %s cannot open, because they are already open. A concert opens its sales once; there is nothing left to do.', $this->describe()),
                ConcertStatus::SOLD_OUT => sprintf('Sales of %s cannot open, because it is already sold out. There is nothing left to sell.', $this->describe()),
                ConcertStatus::CANCELLED => sprintf('Sales of %s cannot open, because the concert is cancelled. Schedule a new concert instead.', $this->describe()),
            });
        }

        $this->status = ConcertStatus::ON_SALE;
        $this->recordThat(new SalesOpened($this->concertId));
    }

    #[CommandHandler]
    public function changeTierPrice(ChangeTierPrice $command): void
    {
        if (! in_array($this->status, [ConcertStatus::SCHEDULED, ConcertStatus::ON_SALE], true)) {
            throw CatalogueRefusal::because(sprintf('The %s price of %s cannot change, because the concert is %s. Prices change only while a concert is scheduled or on sale.', $command->tier->value, $this->describe(), $this->statusInWords()));
        }
        self::assertPrice($this->artist, $command->tier, $command->priceCents);

        match ($command->tier) {
            Tier::STANDARD => $this->standardPriceCents = $command->priceCents,
            Tier::VIP => $this->vipPriceCents = $command->priceCents,
        };
        $this->recordThat(new TierPriceChanged(
            concertId: $this->concertId,
            tier: $command->tier,
            priceCents: $command->priceCents,
            standardPriceCents: $this->standardPriceCents,
            vipPriceCents: $this->vipPriceCents,
        ));
    }

    #[CommandHandler]
    public function cancel(CancelConcert $command): void
    {
        if (! in_array($this->status, [ConcertStatus::SCHEDULED, ConcertStatus::ON_SALE], true)) {
            throw CatalogueRefusal::because(match ($this->status) {
                ConcertStatus::SOLD_OUT => sprintf('%s cannot be cancelled, because it is sold out and every seat has a ticket holder. Only a scheduled or on-sale concert can be cancelled.', ucfirst($this->describe())),
                default => sprintf('%s cannot be cancelled, because it is already cancelled. There is nothing left to do.', ucfirst($this->describe())),
            });
        }

        $this->status = ConcertStatus::CANCELLED;
        $this->recordThat(new ConcertCancelled($this->concertId));
    }

    #[CommandHandler]
    public function markSoldOut(MarkConcertSoldOut $command): void
    {
        if ($this->status !== ConcertStatus::ON_SALE) {
            throw CatalogueRefusal::because(sprintf('%s cannot be marked sold out, because it is %s. Only a concert on sale sells out.', ucfirst($this->describe()), $this->statusInWords()));
        }

        $this->status = ConcertStatus::SOLD_OUT;
        $this->recordThat(new ConcertSoldOut($this->concertId));
    }

    /**
     * @return array{concertId: string, venueId: string, venueName: string, artist: string, startsAt: string, status: string, standardPriceCents: int, vipPriceCents: int, currency: string, seats: list<array{seatId: string, seatCode: string, tier: string}>}
     */
    #[QueryHandler('concert.detail')]
    public function detail(): array
    {
        return [
            'concertId' => $this->concertId,
            'venueId' => $this->venueId,
            'venueName' => $this->venueName,
            'artist' => $this->artist,
            'startsAt' => $this->startsAt->format(DateTimeInterface::ATOM),
            'status' => $this->status->value,
            'standardPriceCents' => $this->standardPriceCents,
            'vipPriceCents' => $this->vipPriceCents,
            'currency' => $this->currency,
            'seats' => $this->seats,
        ];
    }

    public function isOnSale(): bool
    {
        return $this->status === ConcertStatus::ON_SALE;
    }

    public function seat(string $seatId): ?Seat
    {
        foreach ($this->seats as $seat) {
            if ($seat['seatId'] === $seatId) {
                return new Seat(seatId: $seat['seatId'], seatCode: $seat['seatCode'], tier: Tier::from($seat['tier']));
            }
        }

        return null;
    }

    public function seatCount(): int
    {
        return count($this->seats);
    }

    public function priceOf(Tier $tier): int
    {
        return match ($tier) {
            Tier::STANDARD => $this->standardPriceCents,
            Tier::VIP => $this->vipPriceCents,
        };
    }

    public function title(): string
    {
        return sprintf('the concert of %s', $this->artist);
    }

    public function seatRange(): string
    {
        return sprintf('%s to %s', $this->seats[0]['seatCode'] ?? '-', $this->seats[array_key_last($this->seats)]['seatCode'] ?? '-');
    }

    public function statusInWords(): string
    {
        return str_replace('_', ' ', $this->status->value);
    }

    private static function assertPrice(string $artist, Tier $tier, int $priceCents): void
    {
        if ($priceCents < 1) {
            throw CatalogueRefusal::because(sprintf('The %s price of the concert of %s cannot be %d cents, because a ticket costs something. Give a price of 1 cent or more.', $tier->value, $artist, $priceCents));
        }
    }

    private function describe(): string
    {
        return sprintf('the concert of %s (%s)', $this->artist, $this->concertId);
    }
}
