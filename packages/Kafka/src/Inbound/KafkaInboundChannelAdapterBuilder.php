<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
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

final class KafkaInboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    public const DECLARE_ON_STARTUP_DEFAULT = true;
    public const DEFAULT_RECEIVE_TIMEOUT = 10000;

    protected int $receiveTimeoutInMilliseconds = self::DEFAULT_RECEIVE_TIMEOUT;

    protected array $headerMapper = [];

    protected string $acknowledgeMode = KafkaAcknowledgementCallback::AUTO_ACK;

    protected bool $declareOnStartup = self::DECLARE_ON_STARTUP_DEFAULT;


    public function __construct(
        string $endpointId,
        private array $topicsToSubscribe,
        string $requestChannelName
    )
    {
        Assert::allStrings($topicsToSubscribe, "Topics to subscribe must be an array of strings");

        $this->endpointId = $endpointId;
        $this->inboundGateway = GatewayProxyBuilder::create($endpointId, InboundChannelAdapterEntrypoint::class, 'executeEntrypoint', $requestChannelName);
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
                $this->endpointId,
                Reference::to(KafkaAdmin::class),
                $this->topicsToSubscribe,
                $this->receiveTimeoutInMilliseconds,
                Definition::createFor(InboundMessageConverter::class, [
                    Reference::to(KafkaAdmin::class),
                    $this->endpointId,
                    $this->headerMapper,
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
     * @param string $headerMapper
     * @return static
     */
    public function withHeaderMapper(string $headerMapper): self
    {
        $this->headerMapper = explode(',', $headerMapper);

        return $this;
    }

    /**
     * How long it should try to receive message
     *
     * @param int $timeoutInMilliseconds
     * @return static
     */
    public function withReceiveTimeout(int $timeoutInMilliseconds): self
    {
        $this->receiveTimeoutInMilliseconds = $timeoutInMilliseconds;

        return $this;
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

    public function withDeclareOnStartup(bool $declareOnStartup): self
    {
        $this->declareOnStartup = $declareOnStartup;

        return $this;
    }

    /**
     * @return string
     */
    public function getAcknowledgeMode(): string
    {
        return $this->acknowledgeMode;
    }

    public function __toString()
    {
        return 'Inbound Adapter with id ' . $this->endpointId;
    }
}