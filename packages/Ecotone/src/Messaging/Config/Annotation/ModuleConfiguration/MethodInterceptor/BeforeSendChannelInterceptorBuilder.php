<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\InProcessChannel;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
class BeforeSendChannelInterceptorBuilder implements ChannelInterceptorBuilder
{
    private string $inputChannelName;
    private MethodInterceptor $methodInterceptor;
    private GatewayProxyBuilder $gateway;
    private string $internalRequestChannelName;

    public function __construct(string $inputChannelName, MethodInterceptor $methodInterceptor)
    {
        $this->inputChannelName  = $inputChannelName;
        $this->methodInterceptor = $methodInterceptor;

        $this->internalRequestChannelName = Uuid::uuid4()->toString();
        $this->gateway                    = GatewayProxyBuilder::create(BeforeSendGateway::class . $this->internalRequestChannelName, BeforeSendGateway::class, 'execute', $this->internalRequestChannelName);
    }

    /**
     * @inheritDoc
     */
    public function relatedChannelName(): string
    {
        return $this->inputChannelName;
    }

    /**
     * @inheritDoc
     */
    public function getPrecedence(): int
    {
        return $this->methodInterceptor->getPrecedence();
    }

    /**
     * @inheritDoc
     */
    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $messageHandler = $this->methodInterceptor->getInterceptingObject()->compile($builder);
        $builder->register(new ChannelReference($this->internalRequestChannelName), new Definition(InProcessChannel::class, [$this->internalRequestChannelName, $messageHandler], factory: [InProcessChannel::class, 'createDirectChannel']));
        $gateway = $this->gateway->compile($builder);

        return new Definition(BeforeSendChannelInterceptor::class, [$gateway]);
    }

    public function __toString()
    {
        return "{$this->inputChannelName} {$this->methodInterceptor}";
    }
}
