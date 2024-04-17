<?php

namespace Ecotone\Messaging\Support;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;

/**
 * Class ErrorMessage where payload is thrown exception
 * @package Ecotone\Messaging\Support
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
final class ErrorMessage implements Message
{
    private MessagingException $messagingException;
    private MessageHeaders $messageHeaders;

    /**
     * ErrorMessage constructor.
     * @param MessagingException $messagingException
     * @param MessageHeaders $messageHeaders
     */
    private function __construct(MessagingException $messagingException, MessageHeaders $messageHeaders)
    {
        $this->messagingException = $messagingException;
        $this->messageHeaders = $messageHeaders;
    }

    /**
     * @param MessagingException $messagingException
     * @return ErrorMessage
     */
    public static function create(MessagingException $messagingException): self
    {
        return new self($messagingException, $messagingException->getFailedMessage() ? $messagingException->getFailedMessage()->getHeaders() : MessageHeaders::createEmpty());
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(): MessageHeaders
    {
        return $this->messageHeaders;
    }

    /**
     * @inheritDoc
     */
    public function getPayload(): MessagingException
    {
        return $this->messagingException;
    }
}
