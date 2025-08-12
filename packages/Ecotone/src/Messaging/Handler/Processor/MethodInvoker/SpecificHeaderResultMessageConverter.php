<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Enterprise
 */
class SpecificHeaderResultMessageConverter implements ResultToMessageConverter
{
    public function __construct(private string $headerName)
    {
    }

    public function convertToMessage(Message $requestMessage, mixed $result): ?Message
    {
        if (is_null($result)) {
            return null;
        }

        Assert::isFalse($result instanceof Message, 'Message should not be returned when setting specific header in ' . $this->headerName);

        $messageBuilder = MessageBuilder::fromMessage($requestMessage);
        if ($this->headerName === MessageHeaders::ROUTING_SLIP) {
            return $messageBuilder
                ->setRoutingSlip($result)
                ->build();
        }

        return $messageBuilder
            ->setHeader($this->headerName, $result)
            ->build();
    }
}
