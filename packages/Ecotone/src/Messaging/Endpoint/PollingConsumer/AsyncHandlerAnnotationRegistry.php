<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Messaging\Handler\Recoverability\DeadLetterChannels;

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
 */
class AsyncHandlerAnnotationRegistry
{
    /**
     * @param array<string, object[]> $channelToAnnotations
     */
    public function __construct(private array $channelToAnnotations)
    {
    }

    public function withoutDelayedRetries(DeadLetterChannels $deadLetterChannels): self
    {
        return new self(array_map(
            static fn (array $annotations): array => array_map(
                static fn (object $annotation): object => match (true) {
                    $annotation instanceof DelayedRetry && $annotation->deadLetterChannel !== null => new ErrorChannel($annotation->deadLetterChannel),
                    $annotation instanceof ErrorChannel => new ErrorChannel($deadLetterChannels->of($annotation->errorChannelName)),
                    default => $annotation,
                },
                $annotations,
            ),
            $this->channelToAnnotations,
        ));
    }

    /**
     * @return object[]
     */
    public function getAnnotationsForChannel(string $channel): array
    {
        return $this->channelToAnnotations[$channel] ?? [];
    }
}
