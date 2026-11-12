<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Support\MessageBuilder;

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
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 */
final class StoredGatheringMessages
{
    public function __construct(private OutboundMessageConverter $outbound, private ConversionService $conversionService, private MessageBuilder $messageBuilder)
    {
    }

    public function encode(Message $message): string
    {
        $outbound = $this->outbound->prepare($message, $this->conversionService);

        return json_encode(['payload' => base64_encode($outbound->getPayload()), 'headers' => $outbound->getHeaders()], JSON_THROW_ON_ERROR);
    }

    public function decode(string $stored): Message
    {
        $stored = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        return $this->messageBuilder->withPayload(base64_decode($stored['payload'], true))->setMultipleHeaders($stored['headers'])->build();
    }
}
