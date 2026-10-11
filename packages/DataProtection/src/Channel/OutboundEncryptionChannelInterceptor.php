<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */

namespace Ecotone\DataProtection\Channel;

use Ecotone\Api\DataProtection\Key;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class OutboundEncryptionChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(
        private Key $encryptionKey,
        private bool $isPayloadSensitive,
        private array $sensitiveHeaders,
    ) {
        Assert::allStrings($this->sensitiveHeaders, 'Sensitive headers should be array of strings');
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $payload = $message->getPayload();

        if ($this->isPayloadSensitive) {
            $payload = Crypto::encrypt($payload, $this->encryptionKey);
        }

        $headers = $message->getHeaders()->headers();
        foreach ($this->sensitiveHeaders as $sensitiveHeader) {
            if (array_key_exists($sensitiveHeader, $headers)) {
                $headers[$sensitiveHeader] = Crypto::encrypt($headers[$sensitiveHeader], $this->encryptionKey);
            }
        }

        return MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($headers)
            ->build()
        ;
    }
}
