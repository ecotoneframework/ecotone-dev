<?php

namespace Ecotone\DataProtection\Obfuscator;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

readonly class Obfuscator
{
    public function __construct(
        private Key $encryptionKey,
        private array $sensitiveHeaders,
    ) {
        Assert::allStrings($this->sensitiveHeaders, 'Sensitive headers should be array of strings');
    }

    public function encrypt(Message $message): Message
    {
        $encryptedPayload = base64_encode(Crypto::encrypt($message->getPayload(), $this->encryptionKey));
        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = base64_encode(Crypto::encrypt($headers[$sensitiveHeader], $this->encryptionKey));
            }
        }

        $preparedMessage = MessageBuilder::withPayload($encryptedPayload)
            ->setMultipleHeaders($headers)
        ;

        return $preparedMessage->build();
    }

    public function decrypt(Message $message): Message
    {
        $decryptedPayload = Crypto::decrypt(base64_decode($message->getPayload()), $this->encryptionKey);
        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = Crypto::decrypt(base64_decode($headers[$sensitiveHeader]), $this->encryptionKey);
            }
        }

        $preparedMessage = MessageBuilder::withPayload($decryptedPayload)
            ->setMultipleHeaders($headers)
        ;

        return $preparedMessage->build();
    }
}
