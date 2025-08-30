<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Queue;

use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\Support\Assert;
use Illuminate\Support\Facades\Config;

/**
 * licence Apache-2.0
 */
final class LaravelQueueMessageChannelBuilder implements MessageChannelWithSerializationBuilder
{
    private HeaderMapper $headerMapper;

    private function __construct(
        private string $connectionName,
        private string $queueName,
        private string $acknowledgeMode = LaravelQueueAcknowledgementCallback::AUTO_ACK,
        private ?MediaType $defaultOutboundConversionMediaType = null,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND
    ) {
        $this->withHeaderMapping('*');
    }

    public static function create(
        string $queueName,
        ?string $connectionName = null,
    ): self {
        return new self(
            $connectionName ?? Config::get('queue.default'),
            $queueName
        );
    }

    public function getMessageChannelName(): string
    {
        return $this->queueName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    /**
     * @param string $headerMapper
     * @return static
     */
    public function withHeaderMapping(string $headerMapper): self
    {
        $headerMapper = explode(',', $headerMapper);
        $this->headerMapper = DefaultHeaderMapper::createWith($headerMapper, $headerMapper);

        return $this;
    }

    public function withDefaultOutboundConversionMediaType(MediaType $mediaType): self
    {
        $this->defaultOutboundConversionMediaType = $mediaType;

        return $this;
    }

    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        Assert::isTrue($finalFailureStrategy !== FinalFailureStrategy::RELEASE, 'Laravel Queue does not support message release', true);

        $this->finalFailureStrategy = $finalFailureStrategy;

        return $this;
    }

    public function getConversionMediaType(): ?MediaType
    {
        return $this->defaultOutboundConversionMediaType;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        return $this->headerMapper;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(
            LaravelQueueMessageChannel::class,
            [
                new Reference('queue'),
                $this->connectionName,
                $this->queueName,
                $this->acknowledgeMode,
                new Definition(OutboundMessageConverter::class, [
                    $this->headerMapper->getDefinition(),
                    $this->defaultOutboundConversionMediaType?->getDefinition(),
                ]),
                new Reference(ConversionService::REFERENCE_NAME),
                $this->finalFailureStrategy,
            ]
        );
    }
}
