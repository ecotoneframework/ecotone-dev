<?php

namespace Ecotone\Amqp\Publisher;

use Ecotone\Messaging\MessagePublisher;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * Class RegisterAmqpPublisher
 * @package Ecotone\Amqp\Configuration
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
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

    private function __construct(string $connectionReference, string $exchangeName, ?string $outputDefaultConversionMediaType, string $referenceName)
    {
        $this->connectionReference = $connectionReference;
        $this->outputDefaultConversionMediaType = $outputDefaultConversionMediaType;
        $this->referenceName = $referenceName;
        $this->exchangeName = $exchangeName;
    }

    public static function create(string $publisherReferenceName = MessagePublisher::class, string $exchangeName = '', ?string $outputDefaultConversionMediaType = null, string $connectionReference = AmqpConnectionFactory::class): self
    {
        return new self($connectionReference, $exchangeName, $outputDefaultConversionMediaType, $publisherReferenceName);
    }

    /**
     * @return string
     */
    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    /**
     * @param bool $autoDeclareQueueOnSend
     * @return AmqpMessagePublisherConfiguration
     */
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

    /**
     * @return string
     */
    public function getRoutingKeyFromHeader(): string
    {
        return $this->routingKeyFromHeader;
    }

    /**
     * @return string
     */
    public function getDefaultRoutingKey(): string
    {
        return $this->defaultRoutingKey;
    }

    /**
     * @param string $headerMapper comma separated list of headers to be mapped.
     *                             (e.g. "\*" or "thing1*, thing2" or "*thing1")
     *
     * @return AmqpMessagePublisherConfiguration
     */
    public function withHeaderMapper(string $headerMapper): AmqpMessagePublisherConfiguration
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDefaultPersistentDelivery(): bool
    {
        return $this->defaultPersistentDelivery;
    }

    /**
     * @param bool $defaultPersistentDelivery
     * @return AmqpMessagePublisherConfiguration
     */
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
     * @return bool
     */
    public function isAutoDeclareOnSend(): bool
    {
        return $this->autoDeclareOnSend;
    }

    /**
     * @return string
     */
    public function getHeaderMapper(): string
    {
        return $this->headerMapper;
    }

    /**
     * @return string|null
     */
    public function getOutputDefaultConversionMediaType(): ?string
    {
        return $this->outputDefaultConversionMediaType;
    }

    /**
     * @return string
     */
    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    /**
     * @return string
     */
    public function getExchangeName(): string
    {
        return $this->exchangeName;
    }
}
