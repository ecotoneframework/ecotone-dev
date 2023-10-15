<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use InvalidArgumentException;

use function uniqid;

/**
 * Class RouterBuilder
 * @package Ecotone\Messaging\Handler\Router
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class RouterBuilder implements MessageHandlerBuilderWithParameterConverters
{
    private ?string $inputMessageChannelName = null;
    private array $methodParameterConverters = [];
    private bool $resolutionRequired = true;
    /**
     * @var string[]
     */
    private array $requiredReferenceNames = [];
    private ?string $defaultResolution = null;
    private bool $applySequence = false;
    private ?string $endpointId = '';
    private ?DefinedObject $directObjectToInvoke = null;

    private function __construct(private string|Reference|Definition $objectToInvokeReference, private string|InterfaceToCall $methodNameOrInterface)
    {
    }

    public static function create(string|Reference|Definition $objectToInvokeReference, InterfaceToCall $interfaceToCall): self
    {
        return new self($objectToInvokeReference, $interfaceToCall);
    }

    public static function createPayloadTypeRouter(array $typeToChannelMapping): self
    {
        $routerBuilder = new self(new Definition(PayloadTypeRouter::class, [$typeToChannelMapping], 'create'), 'route');

        return $routerBuilder;
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }

    public static function createRouterFromObject(object $customRouterObject, string $methodName): self
    {
        $routerBuilder = new self('', $methodName);
        $routerBuilder->setObjectToInvoke($customRouterObject);

        return $routerBuilder;
    }

    public static function createPayloadTypeRouterByClassName(): self
    {
        $routerBuilder = new self(new Definition(PayloadTypeRouter::class, factory: 'createWithRoutingByClass'), 'route');

        return $routerBuilder;
    }

    public static function createRecipientListRouter(array $recipientLists): self
    {
        $routerBuilder = new self('', 'route');
        $routerBuilder->setObjectToInvoke(new RecipientListRouter($recipientLists));

        return $routerBuilder;
    }

    public static function createHeaderMappingRouter(string $headerName, array $headerValueToChannelMapping): self
    {
        $routerBuilder = new self('', 'route');
        $routerBuilder->setObjectToInvoke(HeaderMappingRouter::create($headerName, $headerValueToChannelMapping));

        return $routerBuilder;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        return $this->requiredReferenceNames;
    }

    /**
     * @inheritDoc
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);

        $this->methodParameterConverters = $methodParameterConverterBuilders;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverters;
    }

    /**
     * @inheritDoc
     */
    public function withInputChannelName(string $inputChannelName): self
    {
        $self = clone $this;
        $self->inputMessageChannelName = $inputChannelName;

        return $self;
    }

    /**
     * @param string $channelName
     * @return RouterBuilder
     */
    public function withDefaultResolutionChannel(string $channelName): self
    {
        $this->defaultResolution = $channelName;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        throw new InvalidArgumentException("Not supported");
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        if ($this->methodNameOrInterface instanceof InterfaceToCall) {
            $interfaceToCall = $this->methodNameOrInterface;
        } elseif ($this->directObjectToInvoke) {
            $className = \get_class($this->directObjectToInvoke);
            $interfaceToCall = $builder->getInterfaceToCall(new InterfaceToCallReference($className, $this->methodNameOrInterface));
        } else {
            $className = $this->objectToInvokeReference instanceof Definition ? $this->objectToInvokeReference->getClassName() : (string) $this->objectToInvokeReference;
            $interfaceToCall = $builder->getInterfaceToCall(new InterfaceToCallReference($className, $this->methodNameOrInterface));
        }
        $methodInvoker = MethodInvoker::createDefinition(
            $builder,
            $interfaceToCall,
            $this->directObjectToInvoke ?: $this->objectToInvokeReference,
            $this->methodParameterConverters
        );
        return $builder->register(uniqid('router.'), new Definition(Router::class, [
            new Reference(ChannelResolver::class),
            $methodInvoker,
            $this->resolutionRequired,
            $this->defaultResolution,
            $this->applySequence,
        ]));
    }

    /**
     * @param bool $resolutionRequired
     * @return RouterBuilder
     */
    public function setResolutionRequired(bool $resolutionRequired): self
    {
        $this->resolutionRequired = $resolutionRequired;

        return $this;
    }

    /**
     * @param bool $applySequence
     *
     * @return RouterBuilder
     */
    public function withApplySequence(bool $applySequence): self
    {
        $this->applySequence = $applySequence;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getInputMessageChannelName(): string
    {
        return $this->inputMessageChannelName;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointId(): ?string
    {
        return $this->endpointId;
    }

    /**
     * @inheritDoc
     */
    public function withEndpointId(string $endpointId): self
    {
        $this->endpointId = $endpointId;

        return $this;
    }

    private function setObjectToInvoke(DefinedObject $objectToInvoke): void
    {
        $this->directObjectToInvoke = $objectToInvoke;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf('Router for input channel `%s` with name `%s`', $this->inputMessageChannelName, $this->getEndpointId());
    }

    private function getMethodName(): string
    {
        return $this->methodNameOrInterface instanceof InterfaceToCall ? $this->methodNameOrInterface->getMethodName() : $this->methodNameOrInterface;
    }

    private function getInterfaceToCall(ContainerMessagingBuilder $builder)
    {

    }
}
