<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Closure;
use DateTimeInterface;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\SleepInterface;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Messaging\Channel\ConsumedInTheSendingProcess;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConsumerCoroutines;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine;
use Swoole\Event;

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
final class CoroutineMessageChannel extends QueueChannel implements ConsumedInTheSendingProcess
{
    private int $consumers = 0;

    public function __construct(private string $name, private ContainerInterface $container, private int $coroutines, private EcotoneClockInterface $clock, private MessageBuilder $messageBuilder, private SleepInterface $sleep)
    {
        parent::__construct($name);
    }

    public function send(Message $message): void
    {
        $delay = $message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY) ? $message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY) : 0;
        $delay = match (true) {
            $delay instanceof TimeSpan => $delay->toMilliseconds(),
            $delay instanceof Duration => $delay->inMilliseconds(),
            $delay instanceof DateTimeInterface => DatePoint::createFromInterface($delay)->durationSince($this->clock->now())->inMilliseconds(),
            default => $delay,
        };
        if ($delay > 0) {
            $dueAt = $this->clock->now()->add(Duration::milliseconds($delay));
            $this->schedule(function () use ($message, $dueAt): void {
                $this->sleep->sleep($dueAt->durationSince($this->clock->now()));
                $this->enqueue($this->messageBuilder->fromMessage($message)->removeHeader(MessageHeaders::DELIVERY_DELAY)->build());
            });
            return;
        }
        $this->enqueue($message);
    }

    private function enqueue(Message $message): void
    {
        parent::send($message);
        if (Coroutine::getCid() < 1) {
            $this->consumer()->createConsumer()->run();
            return;
        }
        if ($this->consumers >= $this->coroutines) {
            return;
        }
        $this->consumers++;
        $this->schedule(function (): void {
            try {
                $runner = $this->consumer();
                do {
                    ConsumerCoroutines::consume(
                        endpointId: $this->name,
                        coroutines: 1,
                        drainsAQueue: true,
                        streamsConsumed: [],
                        createConsumer: static fn (): ConsumerLifecycle => $runner->createConsumer(),
                    );
                } while ($this->hasWaitingMessage());
            } finally {
                $this->consumers--;
            }
        });
    }

    private function hasWaitingMessage(): bool
    {
        $message = parent::receive();
        if ($message === null) {
            return false;
        }
        parent::sendToBeginning($message);

        return true;
    }

    private function consumer(): CoroutineMessageChannelConsumer
    {
        return $this->container->get(CoroutineMessageChannelConsumer::SERVICE_ID_PREFIX . $this->name);
    }

    private function schedule(Closure $work): void
    {
        Event::defer(static fn () => Coroutine::create($work));
    }

    public function __toString(): string
    {
        return 'coroutine message channel: ' . $this->name;
    }
}
