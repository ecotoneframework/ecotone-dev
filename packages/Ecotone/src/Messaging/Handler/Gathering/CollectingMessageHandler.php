<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Closure;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Stringable;

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
        private ?int $maxSize,
        private AttributeExpressionExecutor $groupBy,
        private string $groupByDescription,
        private ?int $maxWait,
        private EcotoneClockInterface $clock,
        private ChannelResolver $channelResolver,
        private Type $payloadType,
        private GatheringConflictRedelivery $redelivery,
    ) {
    }

    public function __toString(): string
    {
        return 'Collecting ' . $this->handler;
    }

    public function handle(Message $message): void
    {
        $timeout = $message->getHeaders()->containsKey(GatheringHeaders::TIMEOUT);
        $manualRelease = $message->getHeaders()->containsKey(GatheringHeaders::MANUAL_RELEASE);
        $reason = match (true) {
            $manualRelease => CollectedReleaseReason::MANUAL_RELEASE,
            $timeout => CollectedReleaseReason::MAX_WAIT,
            default => CollectedReleaseReason::SIZE_REACHED,
        };
        $whenReleased = $this->whenReleased($reason);
        if ($timeout || $manualRelease) {
            if (! $this->store->releaseGroup($message->getHeaders()->get(GatheringHeaders::GROUP_ID), $whenReleased)) {
                $this->redelivery->resendCollected($message, $this->channelName);
            }
            return;
        }
        $recorded = $this->store->collectMessage(
            handler: $this->endpointId,
            groupKey: $this->groupKeyFor($message),
            gatherChannelName: $this->channelName,
            maxSize: $this->maxSize,
            message: $message,
            deadline: $this->maxWait === null ? null : $this->clock->now()->unixTime()->inMilliseconds() + $this->maxWait,
            whenOpened: fn (string $groupId) => $this->scheduleTimeout($groupId, $message),
            whenReleased: $whenReleased,
        );
        if (! $recorded) {
            $this->redelivery->resendCollected($message, $this->channelName);
        }
    }

    private function whenReleased(CollectedReleaseReason $reason): Closure
    {
        return function (Message $original, array $parts, ?int $size, ?string $groupKey) use ($reason): void {
            $this->invokeHandler(parts: $parts, groupKey: $groupKey, reason: $reason);
        };
    }

    private function invokeHandler(array $parts, ?string $groupKey, CollectedReleaseReason $reason): void
    {
        ksort($parts);
        $payloads = [];
        $headers = [];
        foreach ($parts as $part) {
            $payloads[] = $this->payloadOf($part);
            $headers[] = $part->getHeaders()->headers();
        }
        $batch = new CollectedMessages(payloads: $payloads, headers: $headers, groupKey: $groupKey, size: count($payloads), releaseReason: $reason);
        $this->handler->handle($this->messageBuilder->withPayload($batch)->setContentType(MediaType::createApplicationXPHPWithTypeParameter(CollectedMessages::class))->build());
    }

    private function groupKeyFor(Message $message): ?string
    {
        $groupKey = null;
        if ($this->groupBy->hasExpression()) {
            $key = $this->groupBy->execute($this->messageBuilder->fromMessage($message)->setPayload($this->payloadOf($message))->setContentType(MediaType::createApplicationXPHP())->build());
            if ($key === null || (! is_scalar($key) && ! $key instanceof Stringable)) {
                throw InvalidArgumentException::create("Collected handler '{$this->endpointId}' groupBy '{$this->groupByDescription}' must return a non-null scalar or stringable key. Correct groupBy or the message payload/headers, or remove groupBy to collect every message together.");
            }
            $groupKey = (string) $key;
        }
        return $groupKey;
    }

    private function scheduleTimeout(string $groupId, Message $firstMessage): void
    {
        if ($this->maxWait === null) {
            return;
        }
        $trigger = $this->messageBuilder->fromParentMessage($firstMessage)
            ->setPayload($groupId)
            ->removeHeader(MessageHeaders::TIME_TO_LIVE)
            ->removeHeader(MessageHeaders::DELIVERY_DELAY)
            ->setHeader(GatheringHeaders::GROUP_ID, $groupId)
            ->setHeader(GatheringHeaders::TIMEOUT, true)
            ->setHeader(MessageHeaders::DELIVERY_DELAY, $this->maxWait)
            ->build();
        $this->channelResolver->resolve($this->channelName)->send($trigger);
    }

    private function payloadOf(Message $message): mixed
    {
        $mediaType = $message->getHeaders()->hasContentType() ? $message->getHeaders()->getContentType() : MediaType::createApplicationXPHP();
        $type = $this->payloadType->isAnything() && $message->getHeaders()->containsKey(MessageHeaders::TYPE_ID) ? Type::create($message->getHeaders()->get(MessageHeaders::TYPE_ID)) : $this->payloadType;
        if ($mediaType->isCompatibleWith(MediaType::createApplicationXPHP()) && $type->accepts($message->getPayload())) {
            return $message->getPayload();
        }
        return $this->conversionService->convert(source: $message->getPayload(), sourcePHPType: Type::createFromVariable($message->getPayload()), sourceMediaType: $mediaType, targetPHPType: $type, targetMediaType: MediaType::createApplicationXPHP());
    }
}
