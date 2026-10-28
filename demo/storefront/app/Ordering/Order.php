<?php

declare(strict_types=1);

namespace App\Ordering;

use App\Concert\SeatCode;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\IdentifierMethod;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\Modelling\WithEvents;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $concert_id
 * @property OrderStatus $status
 * @property list<array{seatId: string, holdId: string, expiresAt: string}> $held_seats
 * @property array<string, string> $requested_holds
 * @property array{seatId: string, reason: string}|null $last_refusal
 * @property list<array{ticketNumber: string, seatId: string, tier: string, entryCode: string}> $tickets
 * @property string|null $payment_reference
 * @property string|null $failure_reason
 * @property bool $seats_sold
 * @property int $version
 */
#[Aggregate]
final class Order extends Model
{
    use WithEvents;

    public const OPEN_STATUSES = [OrderStatus::STARTED, OrderStatus::SEATS_HELD];

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'customer_id', 'concert_id', 'status', 'held_seats', 'requested_holds', 'tickets'];
    protected $casts = [
        'status' => OrderStatus::class,
        'held_seats' => 'array',
        'requested_holds' => 'array',
        'last_refusal' => 'array',
        'tickets' => 'array',
        'seats_sold' => 'boolean',
    ];

    #[Version]
    private int $version;

    #[CommandHandler]
    public static function start(StartOrder $command): self
    {
        $openOrder = self::query()
            ->where('customer_id', $command->customerId)
            ->where('concert_id', $command->concertId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->first();
        if ($openOrder !== null) {
            throw new DomainException(sprintf(
                'Customer %s already has open order %s for concert %s, so StartOrder cannot start another: a customer has one open order per concert. Hold more seats on order %s, or pay for it or release its seats before starting a new one.',
                $command->customerId,
                $openOrder->id,
                $command->concertId,
                $openOrder->id,
            ));
        }

        return new self([
            'id' => Uuid::v7()->toRfc4122(),
            'customer_id' => $command->customerId,
            'concert_id' => $command->concertId,
            'status' => OrderStatus::STARTED,
            'held_seats' => [],
            'requested_holds' => [],
            'tickets' => [],
        ]);
    }

    #[IdentifierMethod('orderId')]
    public function orderId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return (int) $this->getAttribute('version');
    }

    public function setVersion(int $version): void
    {
        $this->setAttribute('version', $version);
    }

    #[CommandHandler]
    public function requestSeat(RequestSeat $command): void
    {
        $this->assertAccepts('RequestSeat', ...self::OPEN_STATUSES);
        $this->assertSeatOfThisConcert($command->seatId, 'RequestSeat');
        if (in_array($command->seatId, $this->heldSeatIds(), true)) {
            throw new DomainException(sprintf('Order %s already holds seat %s, so RequestSeat does not ask ticketing for it again. Pick another seat, or release this one with ReleaseSeat.', $this->id, $command->seatId));
        }
        $this->requested_holds = [...$this->requested_holds, $command->seatId => $command->holdId];
        $this->recordThat(new SeatRequested(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, seatId: $command->seatId, holdId: $command->holdId));
    }

    #[CommandHandler]
    public function markSeatHeld(MarkSeatHeld $command): void
    {
        $this->assertSeatOfThisConcert($command->seatId, 'MarkSeatHeld');
        if (($this->requested_holds[$command->seatId] ?? null) !== $command->holdId) {
            if ($this->holdIdOf($command->seatId) !== $command->holdId) {
                $this->recordThat(new SeatReleased(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, seatId: $command->seatId, holdId: $command->holdId));
            }

            return;
        }
        if (! in_array($this->status, self::OPEN_STATUSES, true)) {
            $this->forgetRequestOf($command->seatId);
            $this->recordThat(new SeatReleased(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, seatId: $command->seatId, holdId: $command->holdId));

            return;
        }
        try {
            if (trim($command->expiresAt) === '') {
                throw new Exception();
            }
            $expiresAt = (new DateTimeImmutable($command->expiresAt))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
        } catch (Exception) {
            throw new DomainException(sprintf('MarkSeatHeld for seat %s of order %s carries expiresAt "%s", which is not an ISO-8601 date, so the storefront cannot count down the hold. Ticketing sends expiresAt as ISO-8601, like "2026-10-25T12:02:00+00:00": correct the tickets.hold.result reply and replay it from the dbal_dead_letter.', $command->seatId, $this->id, $command->expiresAt));
        }

        $this->forgetRequestOf($command->seatId);
        $this->held_seats = [...$this->heldSeatsWithout($command->seatId), ['seatId' => $command->seatId, 'holdId' => $command->holdId, 'expiresAt' => $expiresAt]];
        if ($this->last_refusal !== null && $this->last_refusal['seatId'] === $command->seatId) {
            $this->last_refusal = null;
        }
        $this->status = OrderStatus::SEATS_HELD;
    }

    #[CommandHandler]
    public function recordSeatRefusal(RecordSeatRefusal $command): void
    {
        if (($this->requested_holds[$command->seatId] ?? null) !== $command->holdId) {
            return;
        }
        $this->forgetRequestOf($command->seatId);
        if (! in_array($this->status, self::OPEN_STATUSES, true)) {
            return;
        }
        $this->last_refusal = ['seatId' => $command->seatId, 'reason' => $command->reason];
    }

    #[CommandHandler]
    public function releaseSeat(ReleaseSeat $command): void
    {
        $this->assertAccepts('ReleaseSeat', ...self::OPEN_STATUSES);
        if (isset($this->requested_holds[$command->seatId]) && ! in_array($command->seatId, $this->heldSeatIds(), true)) {
            $this->forgetRequestOf($command->seatId);

            return;
        }
        if (! in_array($command->seatId, $this->heldSeatIds(), true)) {
            throw new DomainException(sprintf(
                'Order %s does not hold seat %s, so ReleaseSeat cannot release it. The order holds %s: release one of those, or leave the seat alone.',
                $this->id,
                $command->seatId,
                $this->held_seats === [] ? 'no seats' : implode(', ', array_map(SeatCode::ofSeatId(...), $this->heldSeatIds())),
            ));
        }
        $holdId = $this->holdIdOf($command->seatId);
        $this->dropHeldSeat($command->seatId);
        $this->recordThat(new SeatReleased(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, seatId: $command->seatId, holdId: $holdId));
    }

    #[CommandHandler]
    public function dropExpiredSeat(DropExpiredSeat $command): void
    {
        if (! in_array($this->status, self::OPEN_STATUSES, true) || $this->holdIdOf($command->seatId) !== $command->holdId) {
            return;
        }
        $this->dropHeldSeat($command->seatId);
    }

    #[CommandHandler]
    public function beginPayment(BeginPayment $command): void
    {
        $this->assertAccepts('BeginPayment', OrderStatus::SEATS_HELD);
        $this->status = OrderStatus::PAYING;
    }

    #[CommandHandler]
    public function markPaid(MarkPaid $command): void
    {
        if ($this->status === OrderStatus::PAID && $this->payment_reference === $command->paymentReference) {
            return;
        }
        $this->assertAccepts('MarkPaid', OrderStatus::PAYING);
        $this->payment_reference = $command->paymentReference;
        $this->status = OrderStatus::PAID;
        $this->recordThat(new OrderPaid(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, seatIds: $this->heldSeatIds(), paymentReference: $command->paymentReference));
    }

    #[CommandHandler]
    public function markSeatsSold(MarkSeatsSold $command): void
    {
        $this->seats_sold = true;
    }

    #[CommandHandler]
    public function recordConfirmationRefusal(RecordConfirmationRefusal $command): void
    {
        if ($this->seats_sold || $this->status === OrderStatus::TICKETS_ISSUED) {
            return;
        }
        $this->fail(new FailOrder(orderId: $this->id, reason: $command->reason));
    }

    #[CommandHandler]
    public function markTicketsIssued(MarkTicketsIssued $command): void
    {
        if ($this->status === OrderStatus::TICKETS_ISSUED) {
            if (self::sameTickets($command->tickets, $this->tickets)) {
                return;
            }

            throw new DomainException(sprintf(
                'Order %s already has tickets %s issued, but this tickets.issued carries tickets %s, so the storefront cannot tell which ones the customer holds. Keep the issued ones by deleting this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>, and void tickets %s in ticketing.',
                $this->id,
                self::ticketNumbersOf($this->tickets),
                self::ticketNumbersOf($command->tickets),
                self::ticketNumbersOf($command->tickets),
            ));
        }
        if ($this->status === OrderStatus::FAILED) {
            throw new DomainException(sprintf(
                'Order %s failed (%s) and %s, so tickets %s that ticketing issued for it are not recorded. Void tickets %s in ticketing, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>.',
                $this->id,
                $this->failure_reason,
                $this->payment_reference === null ? 'it was never paid' : 'a refund of its payment is queued',
                self::ticketNumbersOf($command->tickets),
                self::ticketNumbersOf($command->tickets),
            ));
        }
        if ($this->status !== OrderStatus::PAID) {
            throw new DomainException(sprintf('Order %s is %s, not paid, so its tickets cannot be issued. Pay for it first: send %s.', $this->id, $this->status->value, match ($this->status) {
                OrderStatus::STARTED => 'MarkSeatHeld, then BeginPayment, then MarkPaid with the payment reference',
                OrderStatus::SEATS_HELD => 'BeginPayment, then MarkPaid with the payment reference',
                default => 'MarkPaid with the payment reference',
            }));
        }
        $this->tickets = $command->tickets;
        $this->status = OrderStatus::TICKETS_ISSUED;
        $this->recordThat(new OrderTicketsIssued(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id));
    }

    #[CommandHandler]
    public function fail(FailOrder $command): void
    {
        $this->assertAccepts('FailOrder', OrderStatus::STARTED, OrderStatus::SEATS_HELD, OrderStatus::PAYING, OrderStatus::PAID);
        $this->failure_reason = $command->reason;
        $this->status = OrderStatus::FAILED;
        $this->recordThat(new OrderFailed(orderId: $this->id, concertId: $this->concert_id, customerId: $this->customer_id, holdIdsOfHeldSeats: array_column($this->held_seats, 'holdId', 'seatId'), paymentReference: $this->payment_reference, reason: $command->reason));
    }

    /**
     * @return list<string>
     */
    public function heldSeatIds(): array
    {
        return array_column($this->held_seats, 'seatId');
    }

    private static function sameTickets(array $tickets, array $otherTickets): bool
    {
        return self::comparable($tickets) === self::comparable($otherTickets);
    }

    private static function comparable(array $tickets): array
    {
        $comparable = array_map(function (array $ticket) {
            ksort($ticket);

            return $ticket;
        }, $tickets);
        usort($comparable, fn (array $ticket, array $otherTicket) => $ticket['ticketNumber'] <=> $otherTicket['ticketNumber']);

        return $comparable;
    }

    private static function ticketNumbersOf(array $tickets): string
    {
        return implode(', ', array_column($tickets, 'ticketNumber'));
    }

    private function dropHeldSeat(string $seatId): void
    {
        $this->held_seats = $this->heldSeatsWithout($seatId);
        if ($this->held_seats === []) {
            $this->status = OrderStatus::STARTED;
        }
    }

    private function holdIdOf(string $seatId): ?string
    {
        foreach ($this->held_seats as $heldSeat) {
            if ($heldSeat['seatId'] === $seatId) {
                return $heldSeat['holdId'];
            }
        }

        return null;
    }

    private function forgetRequestOf(string $seatId): void
    {
        $requestedHolds = $this->requested_holds;
        unset($requestedHolds[$seatId]);
        $this->requested_holds = $requestedHolds;
    }

    private function heldSeatsWithout(string $seatId): array
    {
        return array_values(array_filter($this->held_seats, fn (array $heldSeat) => $heldSeat['seatId'] !== $seatId));
    }

    private function assertSeatOfThisConcert(string $seatId, string $commandName): void
    {
        $seatCode = SeatCode::ofSeatId($seatId);
        $seatConcertId = substr($seatId, 0, -strlen($seatCode) - 1);
        if ($seatConcertId !== $this->concert_id) {
            throw new DomainException(sprintf('Seat %s belongs to concert %s, but order %s is for concert %s, so %s cannot add it. Hold a seat of concert %s, or start an order for concert %s with StartOrder.', $seatId, $seatConcertId, $this->id, $this->concert_id, $commandName, $this->concert_id, $seatConcertId));
        }
    }

    private function assertNotFinal(string $commandName): void
    {
        if ($this->status === OrderStatus::TICKETS_ISSUED) {
            throw new DomainException(sprintf('Order %s already has its tickets issued, so %s cannot be applied to it: an order with issued tickets is final. Start a new order with StartOrder to buy more tickets.', $this->id, $commandName));
        }
        if ($this->status === OrderStatus::FAILED) {
            throw new DomainException(sprintf('Order %s is failed, so %s cannot be applied to it: a failed order is final. Start a new order with StartOrder for the same customer and concert.', $this->id, $commandName));
        }
    }

    private function assertAccepts(string $commandName, OrderStatus ...$acceptingStatuses): void
    {
        $this->assertNotFinal($commandName);
        if (! in_array($this->status, $acceptingStatuses, true)) {
            throw new DomainException(sprintf(
                'Order %s is %s, so %s cannot be applied to it. %s is accepted only while the order is %s.',
                $this->id,
                $this->status->value,
                $commandName,
                $commandName,
                implode(' or ', array_map(fn (OrderStatus $status) => $status->value, $acceptingStatuses)),
            ));
        }
    }
}
