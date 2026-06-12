<?php

/*
 * licence Apache-2.0
 */

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Processor\CodeGeneration\AroundInterceptorMetadata;
use Ecotone\Messaging\Handler\Processor\CodeGeneration\InterceptedMessageProcessorCodeRenderer;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\PayloadResultMessageConverter;

class GatewayInternalProcessorBuilder implements InterceptedMessageProcessorBuilder
{
    /**
     * @param string[] $asynchronousChannels
     */
    public function __construct(
        private InterfaceToCallReference $interfaceToCallReference,
        private string                   $requestChannelName,
        private array                    $asynchronousChannels,
        private ?string                  $replyChannelName,
        private int                      $replyMilliSecondsTimeout,
    ) {
    }

    public function compile(MessagingContainerBuilder $builder, array $aroundInterceptors = []): Definition|Reference
    {
        $routingSlipChannels = $this->asynchronousChannels;
        $routingSlipChannels[] = $this->requestChannelName;

        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);
        $gatewayInternalProcessor = new Definition(GatewayInternalProcessor::class, [
            $interfaceToCall->toString(),
            $interfaceToCall->getReturnType(),
            $interfaceToCall->canItReturnNull(),
            new ChannelReference(array_shift($routingSlipChannels)),
            $routingSlipChannels,
            $this->replyChannelName ? new ChannelReference($this->replyChannelName) : null,
            $this->replyMilliSecondsTimeout,
        ]);

        if ($aroundInterceptors) {
            $sanitizedName = preg_replace('/[^A-Za-z0-9_]/', '_', $interfaceToCall->getInterfaceName() . '_' . $interfaceToCall->getMethodName());
            $generatedClass = $builder->generateClass(
                'MessageProcessor__gateway_' . substr($sanitizedName, 0, 150),
                fn (string $className) => (new InterceptedMessageProcessorCodeRenderer())->render(
                    $className,
                    'process',
                    array_map(
                        fn (Definition $aroundInterceptor) => new AroundInterceptorMetadata(
                            $aroundInterceptor->getArguments()[1],
                            $aroundInterceptor->getArguments()[3],
                        ),
                        $aroundInterceptors,
                    ),
                ),
            );

            return (new Definition($generatedClass->className, [
                $gatewayInternalProcessor,
                new Definition(PayloadResultMessageConverter::class, [$interfaceToCall->getReturnType()]),
                ...array_values($aroundInterceptors),
            ]))->withFile($generatedClass->filePath);
        } else {
            return $gatewayInternalProcessor;
        }
    }

    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}
