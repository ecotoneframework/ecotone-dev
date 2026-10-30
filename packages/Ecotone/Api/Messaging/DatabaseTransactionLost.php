<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging;

use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 */
final class DatabaseTransactionLost extends RuntimeException
{
    public static function whileSendingTo(string $channelName, Throwable $cause): self
    {
        return new self(
            "The database connection was lost while a message was being sent to `{$channelName}` inside a database transaction, so the message was not stored. "
            . 'It is not retried in place: the transaction it belonged to is gone, and a retry would store the message on a new connection, outside that transaction. '
            . "The transaction rolls back and the command or consumed message is retried as a whole: the consumer redelivers an asynchronous endpoint's message, and the command bus instant retries, on by default when DbalConfiguration runs the command bus in a transaction, retry a command. "
            . 'If they were turned off, turn them back on with InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true), and if they list their retry exceptions, include DatabaseTransactionLost::class. '
            . 'To store the message outside the transaction and retry it in place instead, run its handler without a database transaction: DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false) or ->withTransactionOnAsynchronousEndpoints(false).',
            0,
            $cause,
        );
    }
}
