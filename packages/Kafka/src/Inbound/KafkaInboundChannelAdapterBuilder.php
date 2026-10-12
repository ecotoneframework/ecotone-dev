<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\Kafka\KafkaHeader;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedChannelAdapterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\NullEntrypointGateway;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class KafkaInboundChannelAdapterBuilder extends InterceptedChannelAdapterBuilder
{
    public const DECLARE_ON_STARTUP_DEFAULT = true;

    protected bool $declareOnStartup = self::DECLARE_ON_STARTUP_DEFAULT;

    private int $receiveTimeoutInMilliseconds = KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT;

    private int $commitIntervalInMessages = 1;

    public function __construct(
        private string $channelName,
        ?string  $requestChannelName = null,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND,
    ) {
        $this->endpointId = $channelName;
        $this->inboundGateway = $requestChannelName
            ? GatewayProxyBuilder::create('', InboundChannelAdapterEntrypoint::class, 'executeEntrypoint', $requestChannelName)
            : NullEntrypointGateway::create();
    }

    public static function create(
        string $channelName,
        ?string $requestChannelName = null,
    ): self {
        return new self(
            $channelName,
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
                Reference::to(KafkaAdmin::class),
                Definition::createFor(InboundMessageConverter::class, [
                    Reference::to(KafkaAdmin::class),
                    KafkaHeader::ACKNOWLEDGE_HEADER_NAME,
                    $this->finalFailureStrategy,
                    Reference::to(LoggingGateway::class),
                ]),
                Reference::to(ConversionService::REFERENCE_NAME),
                $this->receiveTimeoutInMilliseconds,
                Reference::to(LoggingGateway::class),
                $this->commitIntervalInMessages,
                $this->channelName,
            ]
        );
    }

    /**
     * @return string
     */
    public function getEndpointId(): string
    {
        return $this->channelName;
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
