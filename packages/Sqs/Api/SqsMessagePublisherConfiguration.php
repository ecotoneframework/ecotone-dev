<?php

declare(strict_types=1);

namespace Ecotone\Api\Sqs;

use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Apache-2.0
 */
final class SqsMessagePublisherConfiguration
{
    private bool $autoDeclareOnSend = true;
    private string $headerMapper = '';
    private bool $batchPublishing = false;
    private bool $nonBlockingConfirmation = false;
    private ?int $confirmationTimeoutInMilliseconds = null;

    private function __construct(private string $connectionReference, private string $queueName, private ?string $outputDefaultConversionMediaType, private string $referenceName)
    {
    }

    public static function create(string $publisherReferenceName = MessagePublisher::class, string $queueName = '', ?string $outputDefaultConversionMediaType = null, string $connectionReference = SqsConnectionReference::DEFAULT): self
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
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): self
    {
        Assert::isTrue($confirmationTimeoutInMilliseconds === null || $confirmationTimeoutInMilliseconds > 0, 'Confirmation timeout must be a positive amount of milliseconds.');
        $this->batchPublishing = $batchPublishing;
        $this->nonBlockingConfirmation = $nonBlockingConfirmation;
        if ($confirmationTimeoutInMilliseconds !== null) {
            $this->confirmationTimeoutInMilliseconds = $confirmationTimeoutInMilliseconds;
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
        return $this->confirmationTimeoutInMilliseconds;
    }
}
