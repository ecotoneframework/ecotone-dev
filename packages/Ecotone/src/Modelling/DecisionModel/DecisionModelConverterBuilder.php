<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
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
    ) {
    }

    public static function create(InterfaceParameter $parameter): self
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

        return new self($parameter->getName(), $type->toString(), $parameter->doesAllowNulls());
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    public function compile(InterfaceToCall $interfaceToCall): Definition
    {
        $payloadParameterName = $interfaceToCall->getFirstParameter()->getName();

        return new Definition(DecisionModelConverter::class, [
            $this->modelClassName,
            $this->doesAllowNulls,
            Reference::to(DecisionModelDefinitionRegistry::class),
            Reference::to(TaggedEventStore::class),
            new Reference(DecisionModelExecutorRegistry::serviceIdFor($this->modelClassName)),
            Reference::to(DecisionModelAppendConditionCollector::class),
            PayloadBuilder::create($payloadParameterName)->compile($interfaceToCall),
        ]);
    }
}
