<?php

declare(strict_types=1);

namespace App\Notification;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;

interface SentEmails
{
    #[DbalWrite('INSERT INTO sent_emails (order_id, recipient, subject, body) VALUES (:orderId, :recipient, :subject, :body)')]
    public function record(string $orderId, string $recipient, string $subject, string $body): void;

    #[DbalQuery('SELECT order_id, recipient, subject, body, sent_at FROM sent_emails ORDER BY id')]
    public function all(): array;
}
