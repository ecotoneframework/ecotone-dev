<?php

declare(strict_types=1);

namespace Ecotone\Api\Kafka;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Kafka\Channel\KafkaMessageChannel;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapterBuilder;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class KafkaMessageChannelBuilder implements MessageChannelWithSerializationBuilder
{
    private KafkaInboundChannelAdapterBuilder $inboundChannelAdapterBuilder;
    private KafkaOutboundChannelAdapterBuilder $outboundChannelAdapterBuilder;
    private string $headerMapper;
    private ?MediaType $conversionMediaType = null;
    private bool $batchPublishing = false;
    private bool $requireExistingDestination = false;
    private bool $nonBlockingConfirmation = false;
    private ?int $confirmationTimeout = null;

    private function __construct(
        private string         $channelName,
        public readonly string $topicName,
        public readonly string $messageGroupId,
        int             $receiveTimeoutInMilliseconds = KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT,
    ) {
        $this->inboundChannelAdapterBuilder = KafkaInboundChannelAdapterBuilder::create($this->channelName)
            ->withReceiveTimeout($receiveTimeoutInMilliseconds);
        $this->outboundChannelAdapterBuilder = KafkaOutboundChannelAdapterBuilder::create($channelName);

        $this->headerMapper = '*';
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            KafkaMessageChannel::class,
            [
                $this->inboundChannelAdapterBuilder
                    ->compile($builder),
                $this->outboundChannelAdapterBuilder
                    ->withRequiredExistingDestination($this->requireExistingDestination)
                    ->withHeaderMapper($this->getHeaderMapper())
                    ->withDefaultConversionMediaType($this->conversionMediaType?->toString())
                    ->compile($builder),
            ]
        );
    }

    /**
     * @link https://docs.ecotone.tech/modules/kafka-support/message-channel
     */
    public static function create(
        string  $channelName,
        ?string $topicName = null,
        ?string $messageGroupId = null
    ): self {
        return new self(
            $channelName,
            $topicName ?? $channelName,
            $messageGroupId ?? $channelName,
        );
    }

    public function withRequiredExistingDestination(): self
    {
        $this->requireExistingDestination = true;

        return $this;
    }

    public function getConversionMediaType(): ?MediaType
    {
        return $this->conversionMediaType;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        $headerMapper = explode(',', $this->headerMapper);

        return DefaultHeaderMapper::createWith($headerMapper, $headerMapper);
    }

    public function withHeaderMapping(string $headerMapper): self
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        $this->inboundChannelAdapterBuilder->withFinalFailureStrategy($finalFailureStrategy);

        return $this;
    }

    /**
     * @return static
     */
    public function withReceiveTimeout(int $timeoutInMilliseconds): self
    {
        $this->inboundChannelAdapterBuilder->withReceiveTimeout($timeoutInMilliseconds);

        return $this;
    }

    public function withDefaultConversionMediaType(string $mediaType): self
    {
        $this->conversionMediaType = MediaType::parseMediaType($mediaType);

        return $this;
    }

    /**
     * @link https://docs.ecotone.tech/modules/kafka-support/message-channel#high-throughput-publishing-enterprise
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

    /**
     * @return static
     */
    public function withCommitInterval(int $commitIntervalInMessages): self
    {
        $this->inboundChannelAdapterBuilder->withCommitInterval($commitIntervalInMessages);

        return $this;
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return true;
    }
}
