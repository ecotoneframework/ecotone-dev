<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection\MessageEncryption;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

readonly class MessageEncryptor
{
    public function __construct(
        private Key $encryptionKey,
        private bool $isPayloadSensitive,
        private array $sensitiveHeaders,
    ) {
        Assert::allStrings($this->sensitiveHeaders, 'Sensitive headers should be array of strings');
    }

    public function encrypt(Message $message): Message
    {
        $payload = $message->getPayload();

        if ($this->isPayloadSensitive) {
            $payload = base64_encode(Crypto::encrypt($payload, $this->encryptionKey));
        }

        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = base64_encode(Crypto::encrypt($headers[$sensitiveHeader], $this->encryptionKey));
            }
        }

        return MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($headers)
            ->build()
        ;
    }

    public function decrypt(Message $message): Message
    {
        $payload = $message->getPayload();
        if ($this->isPayloadSensitive) {
            $payload = Crypto::decrypt(base64_decode($payload), $this->encryptionKey);
        }

        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = Crypto::decrypt(base64_decode($headers[$sensitiveHeader]), $this->encryptionKey);
            }
        }

        return MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($headers)
            ->build()
        ;
    }
}
