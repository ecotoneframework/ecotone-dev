<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

/**
 * licence Apache-2.0
 */
abstract class EnqueueMessageConsumerConfiguration
{
    /**
     * comma separated list of headers to be mapped. (e.g. "\*" or "thing1*, thing2" or "*thing1")
     */
    protected string $headerMapper = '';
    protected int $receiveTimeoutInMilliseconds = EnqueueInboundChannelAdapterBuilder::DEFAULT_RECEIVE_TIMEOUT;

    protected function __construct(private string $endpointId, private string $queueName, private string $connectionReferenceName)
    {
    }


    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }

    public function getHeaderMapper(): string
    {
        return $this->headerMapper;
    }

    public function withHeaderMapper(string $headerMapper): static
    {
        $self = clone $this;

        $self->headerMapper = $headerMapper;

        return $self;
    }

    public function getReceiveTimeoutInMilliseconds(): int
    {
        return $this->receiveTimeoutInMilliseconds;
    }

    public function withReceiveTimeoutInMilliseconds(int $receiveTimeoutInMilliseconds): static
    {
        $self = clone $this;
        $self->receiveTimeoutInMilliseconds = $receiveTimeoutInMilliseconds;

        return $self;
    }
}
