<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedChannelAdapterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\NullEntrypointGateway;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * licence Apache-2.0
 */
abstract class EnqueueInboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    public const DECLARE_ON_STARTUP_DEFAULT = true;
    public const DEFAULT_RECEIVE_TIMEOUT = 10000;

    /**
     * @var int
     */
    protected $receiveTimeoutInMilliseconds = self::DEFAULT_RECEIVE_TIMEOUT;

    protected array $headerMapper = [];
    /**
     * @var string
     */
    protected $acknowledgeMode = EnqueueAcknowledgementCallback::AUTO_ACK;
    /**
     * @var FinalFailureStrategy
     */
    protected $finalFailureStrategy = FinalFailureStrategy::RESEND;

    protected bool $declareOnStartup = self::DECLARE_ON_STARTUP_DEFAULT;

    protected string $messageChannelName;

    protected string $connectionReferenceName;


    public function __construct(string $messageChannelName, string $endpointId, ?string $requestChannelName, string $connectionReferenceName)
    {
        $this->messageChannelName = $messageChannelName;
        $this->connectionReferenceName = $connectionReferenceName;
        $this->endpointId = $endpointId;
        $this->inboundGateway = $requestChannelName
            ? GatewayProxyBuilder::create($endpointId, InboundChannelAdapterEntrypoint::class, 'executeEntrypoint', $requestChannelName)
            : NullEntrypointGateway::create();
    }

    protected function compileGateway(MessagingContainerBuilder $builder): Definition|Reference|DefinedObject
    {
        if ($this->isNullableGateway()) {
            return $this->inboundGateway;
        }
        return parent::compileGateway($builder);
    }

    /**
     * @return string
     */
    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    public function getMessageChannelName(): string
    {
        return $this->messageChannelName;
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
     * Set the final failure strategy for message acknowledgment
     *
     * @param FinalFailureStrategy $finalFailureStrategy
     * @return static
     */
    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        $this->finalFailureStrategy = $finalFailureStrategy;

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

    /**
     * @return bool
     */
    private function isNullableGateway(): bool
    {
        return $this->inboundGateway instanceof NullEntrypointGateway;
    }
}
