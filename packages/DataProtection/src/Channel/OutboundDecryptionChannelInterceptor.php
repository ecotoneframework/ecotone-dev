<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\Channel;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class OutboundDecryptionChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(
        private Key $encryptionKey,
        private bool $isPayloadSensitive,
        private array $sensitiveHeaders,
    ) {
        Assert::allStrings($this->sensitiveHeaders, 'Sensitive headers should be array of strings');
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        $payload = $message->getPayload();
        if ($this->isPayloadSensitive) {
            $payload = Crypto::decrypt($payload, $this->encryptionKey);
        }

        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = Crypto::decrypt($headers[$sensitiveHeader], $this->encryptionKey);
            }
        }

        return MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($headers)
            ->build()
        ;
    }
}
