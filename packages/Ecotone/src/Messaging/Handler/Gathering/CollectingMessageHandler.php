<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageHandler;
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
final class CollectingMessageHandler implements MessageHandler
{
    public function __construct(
        private MessageHandler $handler,
        private GatheredGroupStore $store,
        private MessageBuilder $messageBuilder,
        private ConversionService $conversionService,
        private string $endpointId,
        private string $channelName,
        private int $maxSize,
    ) {
    }

    public function __toString(): string
    {
        return 'Collecting ' . $this->handler;
    }

    public function handle(Message $message): void
    {
        $this->store->collectMessage(
            handler: $this->endpointId,
            groupKey: null,
            gatherChannelName: $this->channelName,
            maxSize: $this->maxSize,
            message: $message,
            whenReleased: function (Message $original, array $parts, int $size): void {
                ksort($parts);
                $payloads = [];
                $headers = [];
                foreach ($parts as $part) {
                    $payloads[] = $this->payloadOf($part);
                    $headers[] = $part->getHeaders()->headers();
                }
                $batch = new CollectedMessages(payloads: $payloads, headers: $headers, groupKey: null, size: count($payloads), releaseReason: CollectedReleaseReason::SIZE_REACHED);
                $this->handler->handle($this->messageBuilder->withPayload($batch)->setContentType(MediaType::createApplicationXPHPWithTypeParameter(CollectedMessages::class))->build());
            },
        );
    }

    private function payloadOf(Message $message): mixed
    {
        $mediaType = $message->getHeaders()->hasContentType() ? $message->getHeaders()->getContentType() : MediaType::createApplicationXPHP();
        if ($mediaType->isCompatibleWith(MediaType::createApplicationXPHP())) {
            return $message->getPayload();
        }
        $type = $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID) ? Type::create($message->getHeaders()->get(MessageHeaders::TYPE_ID)) : Type::anything();
        return $this->conversionService->convert(source: $message->getPayload(), sourcePHPType: Type::createFromVariable($message->getPayload()), sourceMediaType: $mediaType, targetPHPType: $type, targetMediaType: MediaType::createApplicationXPHP());
    }
}
