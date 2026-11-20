<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Api\Messaging\PollableChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\EndpointRunnerReference;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConsumerCoroutines;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine;
use Swoole\Event;
use Closure;

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
final class CoroutineMessageChannel extends QueueChannel
{
    private int $consumers = 0;

    public function __construct(private string $name, private ContainerInterface $container, private int $coroutines, private EcotoneClockInterface $clock, private MessageBuilder $messageBuilder)
    {
        parent::__construct($name);
    }

    public function send(Message $message): void
    {
        $delay = $message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY) ? $message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY) : 0;
        $delay = $delay instanceof TimeSpan ? $delay->toMilliseconds() : $delay;
        if ($delay > 0) {
            $this->schedule(function () use ($message, $delay): void {
                $this->clock->sleep(Duration::milliseconds($delay));
                $this->enqueue($this->messageBuilder->fromMessage($message)->removeHeader(MessageHeaders::DELIVERY_DELAY)->build());
            });
            return;
        }
        $this->enqueue($message);
    }

    private function enqueue(Message $message): void
    {
        parent::send($message);
        if ($this->consumers >= $this->coroutines) {
            return;
        }
        $this->consumers++;
        $this->schedule(function (): void {
            try {
                /** @var CoroutineChannelConsumer $runner */
                $runner = $this->container->get((string) new EndpointRunnerReference($this->name));
                ConsumerCoroutines::consume(
                    endpointId: $this->name,
                    coroutines: 1,
                    drainsAQueue: true,
                    streamsConsumed: [],
                    createConsumer: static fn (): ConsumerLifecycle => $runner->createConsumer(),
                );
            } finally {
                $this->consumers--;
            }
        });
    }

    private function schedule(Closure $work): void
    {
        Event::defer(static fn () => Coroutine::create($work));
    }

    public function receive(): ?Message
    {
        return parent::receive();
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
