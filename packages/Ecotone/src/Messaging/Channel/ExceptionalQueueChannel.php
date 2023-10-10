<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\PollableChannel;
use RuntimeException;

class ExceptionalQueueChannel implements PollableChannel, MessageChannelWithSerializationBuilder, CompilableBuilder
{
    private int $exceptionCount = 0;
    private QueueChannel $queueChannel;

    public function __construct(private string $channelName, private bool $exceptionOnReceive, private bool $exceptionOnSend, private int $stopFailingAfterAttempt)
    {
        $this->queueChannel = QueueChannel::create();
    }

    public static function createWithExceptionOnReceive(string $channelName = 'exceptionalChannel', int $stopFailingAfterAttempt = 100): self
    {
        return new self($channelName, true, false, $stopFailingAfterAttempt);
    }

    public static function createWithExceptionOnSend(string $channelName = 'exceptionalChannel', int $stopFailingAfterAttempt = 100): self
    {
        return new self($channelName, false, true, $stopFailingAfterAttempt);
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): void
    {
        if ($this->exceptionOnSend && $this->exceptionCount < $this->stopFailingAfterAttempt) {
            $this->exceptionCount++;
            throw new RuntimeException('Exception on send');
        }

        $this->queueChannel->send($message);
    }

    /**
     * @inheritDoc
     */
    public function receive(): ?Message
    {
        if ($this->exceptionOnReceive && $this->exceptionCount < $this->stopFailingAfterAttempt) {
            $this->exceptionCount++;
            throw new ConnectionException();
        }

        return $this->queueChannel->receive();
    }

    public function getConversionMediaType(): ?MediaType
    {
        return null;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        return DefaultHeaderMapper::createAllHeadersMapping();
    }

    /**
     * @inheritDoc
     */
    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }

    public function getExceptionCount(): int
    {
        return $this->exceptionCount;
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function build(ReferenceSearchService $referenceSearchService): MessageChannel
    {
        return $this;
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return $builder->register(new ChannelReference($this->channelName), new Definition(ExceptionalQueueChannel::class, [
            $this->channelName,
            $this->exceptionOnReceive,
            $this->exceptionOnSend,
            $this->stopFailingAfterAttempt
        ]));
    }

    public function getRequiredReferenceNames(): array
    {
        return [];
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }
}
