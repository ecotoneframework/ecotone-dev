<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Configuration;

use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\Assert;
use Enqueue\Sqs\SqsConnectionFactory;

/**
 * licence Apache-2.0
 */
final class SqsMessagePublisherConfiguration
{
    private bool $autoDeclareOnSend = true;
    private string $headerMapper = '';
    private bool $batchPublishing = false;
    private bool $nonBlockingConfirmation = false;
    private ?int $confirmationTimeout = null;

    private function __construct(private string $connectionReference, private string $queueName, private ?string $outputDefaultConversionMediaType, private string $referenceName)
    {
    }

    public static function create(string $publisherReferenceName = MessagePublisher::class, string $queueName = '', ?string $outputDefaultConversionMediaType = null, string $connectionReference = SqsConnectionFactory::class): self
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
     * @param bool $batchPublishing coalesces published Messages into SQS batch send requests
     * @param bool $nonBlockingConfirmation dispatches send requests without waiting on their responses, which are awaited on Future::resolve() or before the surrounding Command Bus or asynchronous endpoint finishes
     * @param int|null $confirmationTimeoutInMilliseconds how long to await a send response before treating the delivery as failed
     */
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): self
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
}
