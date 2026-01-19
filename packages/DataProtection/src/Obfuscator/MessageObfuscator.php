<?php

/**
 * licence Enterprise
 */
namespace Ecotone\DataProtection\Obfuscator;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;

class MessageObfuscator
{
    /**
     * @param array<string, Obfuscator> $obfuscators
     */
    public function __construct(private array $obfuscators)
    {
    }

    public function encrypt(Message $message): string
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return $message->getPayload();
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        if (! array_key_exists($type, $this->obfuscators)) {
            return $message->getPayload();
        }

        return $this->obfuscators[$type]->encrypt($message->getPayload());
    }

    public function decrypt(Message $message): string
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return $message->getPayload();
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        if (! array_key_exists($type, $this->obfuscators)) {
            return $message->getPayload();
        }

        return $this->obfuscators[$type]->decrypt($message->getPayload());
    }
}
