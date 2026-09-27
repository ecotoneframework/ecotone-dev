<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Closure;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutorCompiler;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Type\ObjectType;
use Ecotone\Messaging\Handler\Type\UnionType;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelConverterBuilder implements ParameterConverterBuilder
{
    private function __construct(
        private readonly string $parameterName,
        private readonly string $modelClassName,
        private readonly bool $doesAllowNulls,
        private readonly string|Closure|null $fetchExpression,
        private readonly ?AttributeDeclaration $attributeDeclaration,
    ) {
    }

    public static function create(InterfaceParameter $parameter, string|Closure|null $fetchExpression = null, ?AttributeDeclaration $attributeDeclaration = null): self
    {
        $type = $parameter->getTypeDescriptor();
        if ($type instanceof UnionType) {
            $type = $type->withoutNull();
        }

        if (! $type instanceof ObjectType) {
            throw ConfigurationException::create(sprintf(
                'DecisionModel parameter %s must be typed with an object type, got %s.',
                $parameter->getName(),
                $parameter->getTypeDescriptor()->toString()
            ));
        }

        return new self($parameter->getName(), $type->toString(), $parameter->doesAllowNulls(), $fetchExpression, $attributeDeclaration);
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    public function parameterName(): string
    {
        return $this->parameterName;
    }

    public function modelClassName(): string
    {
        return $this->modelClassName;
    }

    public function compile(InterfaceToCall $interfaceToCall): Definition
    {
        return new Definition(DecisionModelConverter::class, [
            $this->parameterName,
            new Reference(DecisionModelBatchLoaderRegistry::serviceIdFor($interfaceToCall->getInterfaceName(), $interfaceToCall->getMethodName())),
            Reference::to(DecisionModelLoadedInstancesCollector::class),
        ]);
    }

    public function compileLoader(InterfaceToCall $interfaceToCall): Definition
    {
        $payloadParameterName = $interfaceToCall->getFirstParameter()->getName();

        return new Definition(DecisionModelParameterLoader::class, [
            $this->parameterName,
            $this->modelClassName,
            $this->doesAllowNulls,
            Reference::to(DecisionModelDefinitionRegistry::class),
            new Reference(DecisionModelExecutorRegistry::serviceIdFor($this->modelClassName)),
            PayloadBuilder::create($payloadParameterName)->compile($interfaceToCall),
            $this->fetchExpression !== null
                ? AttributeExpressionExecutorCompiler::compile(new Fetch($this->fetchExpression), $this->attributeDeclaration)
                : null,
        ]);
    }
}
