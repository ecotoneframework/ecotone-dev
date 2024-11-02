<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\KafkaHeader;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedChannelAdapterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 */
final class KafkaInboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    public const DECLARE_ON_STARTUP_DEFAULT = true;

    protected bool $declareOnStartup = self::DECLARE_ON_STARTUP_DEFAULT;

    public function __construct(
        private array $topicsToSubscribe,
        private KafkaConsumerConfiguration $consumerConfiguration,
        string                             $requestChannelName,
        private ?string $groupId
    ) {
        Assert::allStrings($topicsToSubscribe, 'Topics to subscribe must be an array of strings');

        $this->inboundGateway = GatewayProxyBuilder::create($this->consumerConfiguration->getEndpointId(), InboundChannelAdapterEntrypoint::class, 'executeEntrypoint', $requestChannelName);
        $this->endpointId = $this->consumerConfiguration->getEndpointId();
    }

    /**
     * @param string[] $topicsToSubscribe
     */
    public static function create(
        array $topicsToSubscribe,
        KafkaConsumerConfiguration $configuration,
        string $requestChannelName,
        ?string $groupId = null,
    ): self {
        return new self(
            $topicsToSubscribe,
            $configuration,
            $requestChannelName,
            $groupId
        );
    }

    protected function compileGateway(MessagingContainerBuilder $builder): Definition|Reference|DefinedObject
    {
        return parent::compileGateway($builder);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            KafkaInboundChannelAdapter::class,
            [
                $this->consumerConfiguration->getEndpointId(),
                $this->topicsToSubscribe,
                $this->groupId ?? $this->endpointId,
                Reference::to(KafkaAdmin::class),
                $this->consumerConfiguration->getDefinition(),
                Reference::to($this->consumerConfiguration->getBrokerConfigurationReference()),
                Definition::createFor(InboundMessageConverter::class, [
                    Reference::to(KafkaAdmin::class),
                    $this->endpointId,
                    $this->consumerConfiguration->getHeaderMapper()->getDefinition(),
                    KafkaHeader::ACKNOWLEDGE_HEADER_NAME,
                    Reference::to(LoggingGateway::class),
                ]),
                Reference::to(ConversionService::REFERENCE_NAME),
            ]
        );
    }

    /**
     * @return string
     */
    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $this->inboundGateway->getInterceptedInterface($interfaceToCallRegistry);
    }

    /**
     * @inheritDoc
     */
    public function withEndpointAnnotations(iterable $endpointAnnotations)
    {
        $this->inboundGateway->withEndpointAnnotations($endpointAnnotations);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointAnnotations(): array
    {
        return $this->inboundGateway->getEndpointAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function getRequiredInterceptorNames(): iterable
    {
        return $this->inboundGateway->getRequiredInterceptorNames();
    }

    /**
     * @inheritDoc
     */
    public function withRequiredInterceptorNames(iterable $interceptorNames)
    {
        $this->inboundGateway->withRequiredInterceptorNames($interceptorNames);

        return $this;
    }

    public function __toString()
    {
        return 'Inbound Adapter with id ' . $this->endpointId;
    }
}
