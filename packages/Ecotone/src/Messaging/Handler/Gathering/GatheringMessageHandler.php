<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\FrameworkHeaders;
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
final class GatheringMessageHandler implements MessageHandler
{
    public function __construct(private MessageHandler $handler, private GatheredGroupStore $store, private MessageBuilder $messageBuilder, private ConversionService $conversionService, private ChannelResolver $channelResolver)
    {
    }

    public function __toString(): string
    {
        return 'Gathering ' . $this->handler;
    }

    public function handle(Message $message): void
    {
        if (! $message->getHeaders()->containsKey(GatheringHeaders::GROUP_ID)) {
            throw InvalidArgumentException::create('A #[Gathered] handler requires a gathering group opened by its splitter. Send a message to the splitter naming this gather channel, or remove #[Gathered] to accept ordinary commands directly.');
        }

        $timeout = $message->getHeaders()->containsKey(GatheringHeaders::TIMEOUT);
        $manualRelease = $message->getHeaders()->containsKey(GatheringHeaders::MANUAL_RELEASE);
        $releaseReason = match (true) {
            $manualRelease => GatheredReleaseReason::MANUAL_RELEASE,
            $timeout => GatheredReleaseReason::TIMEOUT,
            default => GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR,
        };
        $whenReleased = function (Message $original, array $parts, int $size) use ($releaseReason): void {
            ksort($parts);
            $results = [];
            foreach ($parts as $number => $part) {
                $results[$number] = $part->getHeaders()->get(GatheringHeaders::NO_RESULT) ? null : $this->resultOf($part);
            }
            $headers = FrameworkHeaders::unsetAllFrameworkHeaders($original->getHeaders()->headers());
            foreach ([GatheringHeaders::GROUP_ID, GatheringHeaders::PART_NUMBER, GatheringHeaders::SIZE, GatheringHeaders::CHANNEL_NAME, MessageHeaders::MESSAGE_CORRELATION_ID, MessageHeaders::PARENT_MESSAGE_ID, MessageHeaders::TRACING_ID, MessageHeaders::TRACEPARENT, MessageHeaders::TRACESTATE] as $header) {
                if ($original->getHeaders()->containsKey($header)) {
                    $headers[$header] = $original->getHeaders()->get($header);
                }
            }
            $this->handler->handle($this->messageBuilder->withPayload(new GatheredParts(
                results: $results,
                missingPartNumbers: array_values(array_diff($size === 0 ? [] : range(1, $size), array_keys($parts))),
                size: $size,
                originalPayload: $this->resultOf($original),
                releaseReason: $releaseReason,
            ))->setMultipleHeaders($headers)->setContentType(MediaType::createApplicationXPHPWithTypeParameter(GatheredParts::class))->build());
        };
        $groupId = $message->getHeaders()->get(GatheringHeaders::GROUP_ID);
        if ($timeout || $manualRelease) {
            if (! $this->store->releaseGroup($groupId, $whenReleased)) {
                $this->redeliver($message);
            }

            return;
        }
        $recorded = $this->store->completePart(
            groupId: $groupId,
            partNumber: (int) $message->getHeaders()->get(GatheringHeaders::PART_NUMBER),
            report: $message,
            whenComplete: $whenReleased,
        );
        if (! $recorded) {
            $this->redeliver($message);
        }
    }

    private function redeliver(Message $message): void
    {
        $headers = FrameworkHeaders::unsetNonUserKeys($message->getHeaders()->headers());
        if ($message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            $headers[MessageHeaders::TYPE_ID] = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        }
        $replacement = $this->messageBuilder->withPayload($message->getPayload())
            ->setMultipleHeaders($headers)
            ->removeHeaders([MessageHeaders::MESSAGE_ID, MessageHeaders::TIMESTAMP, MessageHeaders::ROUTING_SLIP])
            ->setContentType($message->getHeaders()->hasContentType() ? $message->getHeaders()->getContentType() : MediaType::createApplicationXPHP())
            ->build();
        $this->channelResolver->resolve($message->getHeaders()->get(GatheringHeaders::CHANNEL_NAME))->send($replacement);
    }

    private function resultOf(Message $part): mixed
    {
        $mediaType = $part->getHeaders()->hasContentType() ? $part->getHeaders()->getContentType() : MediaType::createApplicationXPHP();
        if ($mediaType->isCompatibleWith(MediaType::createApplicationXPHP())) {
            return $part->getPayload();
        }
        $targetType = $part->getHeaders()->containsKey(MessageHeaders::TYPE_ID)
            ? Type::create($part->getHeaders()->get(MessageHeaders::TYPE_ID))
            : Type::anything();

        return $this->conversionService->convert(
            source: $part->getPayload(),
            sourcePHPType: Type::createFromVariable($part->getPayload()),
            sourceMediaType: $mediaType,
            targetPHPType: $targetType,
            targetMediaType: MediaType::createApplicationXPHP(),
        );
    }
}
