<?php

declare(strict_types=1);

namespace Ecotone\Modelling\Config\DatabaseTransaction;

use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 */
final class DatabaseTransactionLost extends RuntimeException
{
    public static function whileSendingTo(string $channelName, Throwable $cause): self
    {
        return new self("The database connection was lost inside the transaction a message to `{$channelName}` was sent in. The message is neither retried nor sent to an error channel on its own, because the transaction it belonged to is gone: the transaction rolls back and the command or consumed message is retried as a whole, by instant retries or by the consumer's redelivery.", 0, $cause);
    }
}
