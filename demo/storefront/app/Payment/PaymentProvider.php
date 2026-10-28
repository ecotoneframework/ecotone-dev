<?php

declare(strict_types=1);

namespace App\Payment;

use Ecotone\Api\Attribute\QueryHandler;

final class PaymentProvider
{
    public function __construct(private readonly PaymentAttempts $attempts)
    {
    }

    public function charge(string $orderId, string $paymentReference, PaymentOutcome $outcome): ChargeOutcome
    {
        $recordedOutcome = $this->recordedOutcomeOf($paymentReference);
        if ($recordedOutcome !== null) {
            return $recordedOutcome;
        }
        if ($outcome === PaymentOutcome::PROVIDER_OUTAGE && ! $this->attempts->isRecorded($paymentReference)) {
            $this->attempts->record(paymentReference: $paymentReference, status: 'unavailable');

            throw new PaymentProviderUnavailable(sprintf(
                'The payment provider is unavailable, so payment %s of order %s was not charged. It is a transient outage: replay this ChargeCard message from the dead letters in Ecotone Pulse, or run php artisan ecotone:deadletter:replay <messageId> in the storefront, and the card is charged again.',
                $paymentReference,
                $orderId,
            ));
        }
        $chargeOutcome = $outcome === PaymentOutcome::DECLINED ? ChargeOutcome::DECLINED : ChargeOutcome::CHARGED;
        $this->attempts->record(paymentReference: $paymentReference, status: $chargeOutcome->value);

        return $chargeOutcome;
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

    private function recordedOutcomeOf(string $paymentReference): ?ChargeOutcome
    {
        return match ($this->attempts->statusOf($paymentReference)) {
            'charged', 'refunded' => ChargeOutcome::CHARGED,
            'declined' => ChargeOutcome::DECLINED,
            default => null,
        };
    }
}
