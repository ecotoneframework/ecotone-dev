<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface ResolveAggregateEventsService extends RealMessageProcessor
{
    public function process(Message $message): Message;
}
