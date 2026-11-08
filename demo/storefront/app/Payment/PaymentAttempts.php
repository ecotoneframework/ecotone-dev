<?php

declare(strict_types=1);

namespace App\Payment;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface PaymentAttempts
{
    public const CONNECTION = 'payment_provider_connection';

    #[DbalWrite(<<<'SQL'
        INSERT INTO payment_attempts (payment_reference, attempts, status) VALUES (:paymentReference, 1, :status)
        ON CONFLICT (payment_reference) DO UPDATE SET attempts = payment_attempts.attempts + 1, status = EXCLUDED.status
        SQL, connectionReferenceName: self::CONNECTION)]
    public function record(string $paymentReference, string $status): void;

    #[DbalWrite("UPDATE payment_attempts SET status = 'refunded' WHERE payment_reference = :paymentReference AND status = 'charged'", connectionReferenceName: self::CONNECTION)]
    public function refund(string $paymentReference): int;

    #[DbalQuery('SELECT EXISTS (SELECT 1 FROM payment_attempts WHERE payment_reference = :paymentReference)', fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW, connectionReferenceName: self::CONNECTION)]
    public function isRecorded(string $paymentReference): bool;

    #[DbalQuery('SELECT status FROM payment_attempts WHERE payment_reference = :paymentReference', fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW, connectionReferenceName: self::CONNECTION)]
    public function statusOf(string $paymentReference): string|false;
}
