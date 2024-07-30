<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
class FetchAggregate implements RealMessageProcessor
{
    public function process(Message $message): ?Message
    {
        if ($message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT)) {
            return MessageBuilder::fromMessage($message)
                ->setPayload($message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT))
                ->build();
        }

        if ($message->getHeaders()->containsKey(AggregateMessage::RESULT_AGGREGATE_OBJECT)) {
            return MessageBuilder::fromMessage($message)
                ->setPayload($message->getHeaders()->get(AggregateMessage::RESULT_AGGREGATE_OBJECT))
                ->build();
        }
        return MessageBuilder::fromMessage($message)
            ->setPayload(null)
            ->build();
    }
}
