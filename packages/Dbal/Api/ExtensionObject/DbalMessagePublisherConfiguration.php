<?php

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
class DbalMessagePublisherConfiguration
{
    /**
     * @var string
     */
    private $connectionReference;
    /**
     * @var string|null
     */
    private $outputDefaultConversionMediaType;
    /**
     * @var string
     */
    private $referenceName;
    /**
     * @var bool
     */
    private $autoDeclareQueueOnSend = DbalOutboundChannelAdapterBuilder::DEFAULT_AUTO_DECLARE;
    /**
     * @var string
     */
    private $headerMapper = '';
    /**
     * @var string
     */
    private $queueName;

    private bool $batchPublishing = false;

    private function __construct(string $connectionReference, string $queueName, ?string $outputDefaultConversionMediaType, string $referenceName)
    {
        $this->connectionReference = $connectionReference;
        $this->queueName = $queueName;
        $this->outputDefaultConversionMediaType = $outputDefaultConversionMediaType;
        $this->referenceName = $referenceName;
    }

    public static function create(string $publisherReferenceName, string $queueName, ?string $outputDefaultConversionMediaType = null, string $connectionReference = DbalConnectionReference::DEFAULT): self
    {
        return new self($connectionReference, $queueName, $outputDefaultConversionMediaType, $publisherReferenceName);
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    public function withAutoDeclareQueueOnSend(bool $autoDeclareQueueOnSend): DbalMessagePublisherConfiguration
    {
        $this->autoDeclareQueueOnSend = $autoDeclareQueueOnSend;

        return $this;
    }

    /**
     * @param string $headerMapper comma separated list of headers to be mapped.
     *                             (e.g. "\*" or "thing1*, thing2" or "*thing1")
     */
    public function withHeaderMapper(string $headerMapper): DbalMessagePublisherConfiguration
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    public function isAutoDeclareQueueOnSend(): bool
    {
        return $this->autoDeclareQueueOnSend;
    }

    public function getHeaderMapper(): string
    {
        return $this->headerMapper;
    }

    public function getOutputDefaultConversionMediaType(): ?string
    {
        return $this->outputDefaultConversionMediaType;
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
