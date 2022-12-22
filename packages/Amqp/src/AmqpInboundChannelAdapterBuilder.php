<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Endpoint\TaskExecutorChannelAdapter\TaskExecutorChannelAdapter;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * Class InboundEnqueueGatewayBuilder
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class AmqpInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    private string $amqpConnectionReferenceName;

    private function __construct(string $endpointId, string $queueName, ?string $requestChannelName, string $amqpConnectionReferenceName)
    {
        $this->amqpConnectionReferenceName = $amqpConnectionReferenceName;
        $this->queueName = $queueName;
        $this->initialize($queueName, $endpointId, $requestChannelName, $amqpConnectionReferenceName);
    }

    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, string $amqpConnectionReferenceName = AmqpConnectionFactory::class): self
    {
        return new self($endpointId, $queueName, $requestChannelName, $amqpConnectionReferenceName);
    }

    public function createInboundChannelAdapter(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, PollingMetadata $pollingMetadata): AmqpInboundChannelAdapter
    {
        if ($pollingMetadata->getExecutionTimeLimitInMilliseconds()) {
            $this->withReceiveTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds());
        }

        /** @var AmqpAdmin $amqpAdmin */
        $amqpAdmin = $referenceSearchService->get(AmqpAdmin::REFERENCE_NAME);
        /** @var AmqpConnectionFactory $amqpConnectionFactory */
        $amqpConnectionFactory = $referenceSearchService->get($this->amqpConnectionReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $inboundAmqpGateway = $this->buildGatewayFor($referenceSearchService, $channelResolver, $pollingMetadata);

        $headerMapper = DefaultHeaderMapper::createWith($this->headerMapper, [], $conversionService);

        return new AmqpInboundChannelAdapter(
            CachedConnectionFactory::createFor(new AmqpConsumerConnectionFactory($amqpConnectionFactory)),
            $inboundAmqpGateway,
            $amqpAdmin,
            $this->declareOnStartup,
            $this->queueName,
            $this->receiveTimeoutInMilliseconds,
            new InboundMessageConverter($this->getEndpointId(), $this->acknowledgeMode, AmqpHeader::HEADER_ACKNOWLEDGE, $headerMapper),
        );
    }
}
