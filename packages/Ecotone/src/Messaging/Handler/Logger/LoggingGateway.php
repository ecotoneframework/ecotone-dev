<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Logger;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Message;
use Throwable;

/**
 * licence Apache-2.0
 */
interface LoggingGateway
{
    public function info(
        string $text,
        ?Message $message = null,
        ?Throwable $exception = null,
        array $contextData = [],
    ): void;

    public function error(
        string $text,
        Message $message,
        ?Throwable $exception = null,
        array $contextData = [],
    ): void;
}
