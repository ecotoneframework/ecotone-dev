<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection;

use Ecotone\DataProtection\MessageEncryption\MessageEncryptor;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;

class OutboundDecryptionChannelInterceptor extends AbstractChannelInterceptor
{
    /**
     * @param array<MessageEncryptor> $messageEncryptors
     */
    public function __construct(
        private readonly ?MessageEncryptor $channelEncryptor,
        private readonly array             $messageEncryptors,
    ) {
        Assert::allInstanceOfType($this->messageEncryptors, MessageEncryptor::class);
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (! $message->getHeaders()->getContentType()?->isCompatibleWith(MediaType::createApplicationJson())) {
            return $message;
        }

        if ($messageEncryptor = $this->findMessageEncryptor($message)) {
            return $messageEncryptor->decrypt($message);
        }

        if ($this->channelEncryptor) {
            return $this->channelEncryptor->decrypt($message);
        }

        return $message;
    }

    private function findMessageEncryptor(Message $message): ?MessageEncryptor
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return null;
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);

        return $this->messageEncryptors[$type] ?? null;
    }
}
