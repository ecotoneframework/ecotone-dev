<?php

declare(strict_types=1);

namespace App\Payment;

use Ecotone\Api\Attribute\QueryHandler;

final class PaymentProvider
{
    private const DECLINED_CARD_ENDING = '0000';
    private const TRANSIENT_OUTAGE_CARD_ENDING = '9999';

    public function __construct(private readonly PaymentAttempts $attempts)
    {
    }

    public function charge(string $orderId, string $paymentReference, string $cardEnding): ChargeOutcome
    {
        if ($cardEnding === self::TRANSIENT_OUTAGE_CARD_ENDING && $this->attempts->attemptsOf($paymentReference) === 0) {
            $this->attempts->record(paymentReference: $paymentReference, cardEnding: $cardEnding, status: 'unavailable');

            throw new PaymentProviderUnavailable(sprintf(
                'The payment provider is unavailable, so payment %s of order %s with the card ending %s was not charged. It is a transient outage: replay this ChargeCard message from the dead letters in Ecotone Pulse, or run php artisan ecotone:deadletter:replay <messageId> in the storefront, and the card is charged again.',
                $paymentReference,
                $orderId,
                $cardEnding,
            ));
        }
        $outcome = $cardEnding === self::DECLINED_CARD_ENDING ? ChargeOutcome::DECLINED : ChargeOutcome::CHARGED;
        $this->attempts->record(paymentReference: $paymentReference, cardEnding: $cardEnding, status: $outcome->value);

        return $outcome;
    }

    public function refund(string $paymentReference): void
    {
        $this->attempts->refund($paymentReference);
    }

    #[QueryHandler('payment.status')]
    public function status(string $paymentReference): ?string
    {
        $status = $this->attempts->statusOf($paymentReference);

        return $status === false ? null : $status;
    }
}
