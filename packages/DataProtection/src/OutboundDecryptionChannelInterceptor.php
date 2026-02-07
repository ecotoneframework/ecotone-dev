<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection;

use Ecotone\DataProtection\Obfuscator\Obfuscator;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;

class OutboundDecryptionChannelInterceptor extends AbstractChannelInterceptor
{
    /**
     * @param array<Obfuscator> $messageObfuscators
     */
    public function __construct(
        private readonly ?Obfuscator $channelObfuscator,
        private readonly array $messageObfuscators,
    ) {
        Assert::allInstanceOfType($this->messageObfuscators, Obfuscator::class);
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (! $message->getHeaders()->getContentType()?->isCompatibleWith(MediaType::createApplicationJson())) {
            return $message;
        }

        if ($messageObfuscator = $this->findMessageObfuscator($message)) {
            return $messageObfuscator->decrypt($message);
        }

        if ($this->channelObfuscator) {
            return $this->channelObfuscator->decrypt($message);
        }

        return $message;
    }

    private function findMessageObfuscator(Message $message): ?Obfuscator
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return null;
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);

        return $this->messageObfuscators[$type] ?? null;
    }
}
