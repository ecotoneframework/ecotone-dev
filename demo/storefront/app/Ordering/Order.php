<?php

declare(strict_types=1);

namespace App\Ordering;

use DomainException;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\IdentifierMethod;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $concert_id
 * @property OrderStatus $status
 * @property list<string> $seat_ids
 * @property string|null $payment_reference
 * @property string|null $failure_reason
 */
#[Aggregate]
final class Order extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'customer_id', 'concert_id', 'status', 'seat_ids'];
    protected $casts = [
        'status' => OrderStatus::class,
        'seat_ids' => 'array',
    ];

    #[CommandHandler]
    public static function start(StartOrder $command): self
    {
        return new self([
            'id' => Uuid::v7()->toRfc4122(),
            'customer_id' => $command->customerId,
            'concert_id' => $command->concertId,
            'status' => OrderStatus::STARTED,
            'seat_ids' => [],
        ]);
    }

    #[IdentifierMethod('orderId')]
    public function orderId(): string
    {
        return $this->id;
    }

    #[CommandHandler]
    public function markSeatsHeld(MarkSeatsHeld $command): void
    {
        $this->assertAccepts('MarkSeatsHeld', OrderStatus::STARTED, OrderStatus::SEATS_HELD);
        if ($command->seatIds === []) {
            throw new DomainException(sprintf('MarkSeatsHeld for order %s names no seats, so it cannot move the order to seats_held. Send it with at least one seat id of concert %s, e.g. "%s/A-1".', $this->id, $this->concert_id, $this->concert_id));
        }
        $this->seat_ids = array_values(array_unique([...$this->seat_ids, ...$command->seatIds]));
        $this->status = OrderStatus::SEATS_HELD;
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
        $this->assertAccepts('MarkPaid', OrderStatus::PAYING);
        $this->payment_reference = $command->paymentReference;
        $this->status = OrderStatus::PAID;
    }

    #[CommandHandler]
    public function markTicketsIssued(MarkTicketsIssued $command): void
    {
        $this->assertNotFinal('MarkTicketsIssued');
        if ($this->status !== OrderStatus::PAID) {
            throw new DomainException(sprintf('Order %s is %s, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.', $this->id, $this->status->value));
        }
        $this->status = OrderStatus::TICKETS_ISSUED;
    }

    #[CommandHandler]
    public function fail(FailOrder $command): void
    {
        $this->assertAccepts('FailOrder', OrderStatus::STARTED, OrderStatus::SEATS_HELD, OrderStatus::PAYING, OrderStatus::PAID);
        $this->failure_reason = $command->reason;
        $this->status = OrderStatus::FAILED;
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
