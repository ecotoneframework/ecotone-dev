<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedChannelAdapterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\NullEntrypointGateway;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;

/**
 * licence Enterprise
 */
final class KafkaInboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    public const DECLARE_ON_STARTUP_DEFAULT = true;

    protected bool $declareOnStartup = self::DECLARE_ON_STARTUP_DEFAULT;

    private int $receiveTimeoutInMilliseconds = KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT;

    private int $commitIntervalInMessages = 1;

    public function __construct(
        string $endpointId,
        ?string  $requestChannelName = null,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND,
    ) {
        $this->inboundGateway = $requestChannelName
            ? GatewayProxyBuilder::create($endpointId, InboundChannelAdapterEntrypoint::class, 'executeEntrypoint', $requestChannelName)
            : NullEntrypointGateway::create();
        $this->endpointId = $endpointId;
    }

    public static function create(
        string $endpointId,
        ?string $requestChannelName = null,
    ): self {
        return new self(
            $endpointId,
            $requestChannelName,
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
                $this->endpointId,
                Reference::to(KafkaAdmin::class),
                Definition::createFor(InboundMessageConverter::class, [
                    Reference::to(KafkaAdmin::class),
                    $this->endpointId,
                    KafkaHeader::ACKNOWLEDGE_HEADER_NAME,
                    $this->finalFailureStrategy,
                    Reference::to(LoggingGateway::class),
                ]),
                Reference::to(ConversionService::REFERENCE_NAME),
                $this->receiveTimeoutInMilliseconds,
                Reference::to(LoggingGateway::class),
                $this->commitIntervalInMessages,
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

    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        $this->finalFailureStrategy = $finalFailureStrategy;

        return $this;
    }

    /**
     * Set the commit interval in messages. Offsets will be committed every X messages.
     *
     * @param int $commitIntervalInMessages Number of messages to process before committing offset
     * @return static
     */
    public function withCommitInterval(int $commitIntervalInMessages): self
    {
        $this->commitIntervalInMessages = $commitIntervalInMessages;

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

    public function __toString()
    {
        return 'Inbound Adapter with id ' . $this->endpointId;
    }
}
