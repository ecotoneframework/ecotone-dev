<?php

declare(strict_types=1);

namespace Ecotone\Api\Redis;

use Ecotone\Api\Gateway\MessagePublisher;

/**
 * licence Apache-2.0
 */
final class RedisMessagePublisherConfiguration
{
    private bool $autoDeclareOnSend = true;
    private string $headerMapper = '';
    private bool $batchPublishing = false;

    private function __construct(private string $connectionReference, private string $queueName, private ?string $outputDefaultConversionMediaType, private string $referenceName)
    {
    }

    public static function create(string $publisherReferenceName = MessagePublisher::class, string $queueName = '', ?string $outputDefaultConversionMediaType = null, string $connectionReference = RedisConnectionReference::DEFAULT): self
    {
        return new self($connectionReference, $queueName, $outputDefaultConversionMediaType, $publisherReferenceName);
    }

    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    public function withAutoDeclareQueueOnSend(bool $autoDeclareQueueOnSend): self
    {
        $this->autoDeclareOnSend = $autoDeclareQueueOnSend;

        return $this;
    }

    /**
     * @param string $headerMapper comma separated list of headers to be mapped.
     *                             (e.g. "\*" or "thing1*, thing2" or "*thing1")
     */
    public function withHeaderMapper(string $headerMapper): self
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    public function isAutoDeclareOnSend(): bool
    {
        return $this->autoDeclareOnSend;
    }

    public function getHeaderMapper(): string
    {
        return $this->headerMapper;
    }

    public function getOutputDefaultConversionMediaType(): ?string
    {
        return $this->outputDefaultConversionMediaType;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    /**
     * @link https://docs.ecotone.tech/modelling/microservices-php/message-publisher#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(): self
    {
        $this->batchPublishing = true;

        return $this;
    }

    public function isBatchPublishingEnabled(): bool
    {
        return $this->batchPublishing;
    }
}
