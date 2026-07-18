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
    private bool $asyncPublishing = false;
    private ?int $asyncPublishingTimeout = null;

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

    public function withAsyncPublishing(bool $asyncPublishing = true, ?int $timeoutInMilliseconds = null): self
    {
        Assert::isTrue($timeoutInMilliseconds === null || $timeoutInMilliseconds > 0, 'Async publishing timeout must be a positive amount of milliseconds.');
        $this->asyncPublishing = $asyncPublishing;
        if ($timeoutInMilliseconds !== null) {
            $this->asyncPublishingTimeout = $timeoutInMilliseconds;
        }

        return $this;
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->asyncPublishing;
    }

    public function getAsyncPublishingTimeout(): ?int
    {
        return $this->asyncPublishingTimeout;
    }
}
