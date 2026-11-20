<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\PollableChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\EndpointRunnerReference;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConsumerCoroutines;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine;

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
final class CoroutineMessageChannel implements PollableChannel
{
    private bool $consuming = false;

    public function __construct(private string $name, private QueueChannel $queue, private ContainerInterface $container)
    {
    }

    public function send(Message $message): void
    {
        $this->queue->send($message);
        if ($this->consuming) {
            return;
        }
        $this->consuming = true;
        Coroutine::create(function (): void {
            try {
                /** @var InterceptedConsumerRunner $runner */
                $runner = $this->container->get((string) new EndpointRunnerReference($this->name));
                ConsumerCoroutines::consume(
                    endpointId: $this->name,
                    coroutines: 10,
                    drainsAQueue: true,
                    streamsConsumed: [],
                    createConsumer: static fn (): ConsumerLifecycle => $runner->createConsumer(ExecutionPollingMetadata::createWithFinishWhenNoMessages()),
                );
            } finally {
                $this->consuming = false;
            }
        });
    }

    public function receive(): ?Message
    {
        return $this->queue->receive();
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->receive();
    }

    public function __toString(): string
    {
        return 'coroutine channel: ' . $this->name;
    }

    public function onConsumerStop(): void
    {
    }
}
