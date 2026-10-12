<?php

namespace Ecotone\Api\Amqp;

use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Apache-2.0
 */
class AmqpMessagePublisherConfiguration
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
     * @var string
     */
    private $exchangeName;
    /**
     * @var bool
     */
    private $autoDeclareOnSend = true;
    /**
     * @var string
     */
    private $headerMapper = '';
    /**
     * @var string
     */
    private $defaultRoutingKey = '';
    /**
     * @var string
     */
    private $routingKeyFromHeader = '';
    /**
     * @var bool
     */
    private $defaultPersistentDelivery = true;

    private bool $batchPublishing = false;

    private bool $nonBlockingConfirmation = false;

    private ?int $confirmationTimeout = null;

    private function __construct(string $connectionReference, string $exchangeName, ?string $outputDefaultConversionMediaType, string $referenceName)
    {
        $this->connectionReference = $connectionReference;
        $this->outputDefaultConversionMediaType = $outputDefaultConversionMediaType;
        $this->referenceName = $referenceName;
        $this->exchangeName = $exchangeName;
    }

    public static function create(string $publisherReferenceName = MessagePublisher::class, string $exchangeName = '', ?string $outputDefaultConversionMediaType = null, string $connectionReference = AmqpConnectionReference::DEFAULT): self
    {
        return new self($connectionReference, $exchangeName, $outputDefaultConversionMediaType, $publisherReferenceName);
    }

    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    public function withAutoDeclareQueueOnSend(bool $autoDeclareQueueOnSend): AmqpMessagePublisherConfiguration
    {
        $this->autoDeclareOnSend = $autoDeclareQueueOnSend;

        return $this;
    }

    public function withDefaultRoutingKey(string $defaultRoutingKey): AmqpMessagePublisherConfiguration
    {
        $this->defaultRoutingKey = $defaultRoutingKey;

        return $this;
    }

    public function withRoutingKeyFromHeader(string $headerName): AmqpMessagePublisherConfiguration
    {
        $this->routingKeyFromHeader = $headerName;

        return $this;
    }

    public function getRoutingKeyFromHeader(): string
    {
        return $this->routingKeyFromHeader;
    }

    public function getDefaultRoutingKey(): string
    {
        return $this->defaultRoutingKey;
    }

    /**
     * @param string $headerMapper comma separated list of headers to be mapped.
     *                             (e.g. "\*" or "thing1*, thing2" or "*thing1")
     */
    public function withHeaderMapper(string $headerMapper): AmqpMessagePublisherConfiguration
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    public function isDefaultPersistentDelivery(): bool
    {
        return $this->defaultPersistentDelivery;
    }

    public function withDefaultPersistentDelivery(bool $defaultPersistentDelivery): AmqpMessagePublisherConfiguration
    {
        $this->defaultPersistentDelivery = $defaultPersistentDelivery;
        return $this;
    }

    public function getDefaultPersistentDelivery(): bool
    {
        return $this->defaultPersistentDelivery;
    }

    /**
     * @link https://docs.ecotone.tech/modelling/microservices-php/message-publisher#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): AmqpMessagePublisherConfiguration
    {
        Assert::isTrue($confirmationTimeoutInMilliseconds === null || $confirmationTimeoutInMilliseconds > 0, 'Confirmation timeout must be a positive amount of milliseconds.');
        $this->batchPublishing = $batchPublishing;
        $this->nonBlockingConfirmation = $nonBlockingConfirmation;
        if ($confirmationTimeoutInMilliseconds !== null) {
            $this->confirmationTimeout = $confirmationTimeoutInMilliseconds;
        }

        return $this;
    }

    public function isBatchPublishingEnabled(): bool
    {
        return $this->batchPublishing;
    }

    public function isNonBlockingConfirmationEnabled(): bool
    {
        return $this->nonBlockingConfirmation;
    }

    public function getConfirmationTimeout(): ?int
    {
        return $this->confirmationTimeout;
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

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getExchangeName(): string
    {
        return $this->exchangeName;
    }
}
