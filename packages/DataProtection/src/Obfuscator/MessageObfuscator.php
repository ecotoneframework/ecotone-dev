<?php

/**
 * licence Enterprise
 */
namespace Ecotone\DataProtection\Obfuscator;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class MessageObfuscator
{
    /**
     * @var array<string, Key>
     */
    private array $encryptionKeys = [];

    /**
     * @var array<string, array<string>>
     */
    private array $sensitiveHeaders = [];

    public function encrypt(Message $message): Message
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return $message;
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        if (! array_key_exists($type, $this->encryptionKeys)) {
            return $message;
        }

        $key = $this->encryptionKeys[$type];
        $encryptedPayload = base64_encode(Crypto::encrypt($message->getPayload(), $key));
        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders[$type] as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = base64_encode(Crypto::encrypt($headers[$sensitiveHeader], $key));
            }
        }

        $preparedMessage = MessageBuilder::withPayload($encryptedPayload)
            ->setMultipleHeaders($headers)
        ;

        return $preparedMessage->build();
    }

    public function decrypt(Message $message): Message
    {
        if (! $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return $message;
        }

        $type = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        if (! array_key_exists($type, $this->encryptionKeys)) {
            return $message;
        }

        $key = $this->encryptionKeys[$type];
        $decryptedPayload = Crypto::decrypt(base64_decode($message->getPayload()), $key);
        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders[$type] as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = Crypto::decrypt(base64_decode($headers[$sensitiveHeader]), $key);
            }
        }

        $preparedMessage = MessageBuilder::withPayload($decryptedPayload)
            ->setMultipleHeaders($headers)
        ;

        return $preparedMessage->build();
    }

    public function withKey(string $messageClass, Key $key): void
    {
        $this->encryptionKeys[$messageClass] = $key;
    }

    public function withSensitiveHeaders(string $messageClass, array $headers): void
    {
        Assert::allStrings($headers, sprintf('Headers for message class %s should be array of strings', $messageClass));

        $this->sensitiveHeaders[$messageClass] = $headers;
    }
}
