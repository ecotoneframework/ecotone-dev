<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

final class EventSourcingHandlerMethodBuilder implements DefinedObject
{
    /**
     * @param InterfaceToCall $interfaceToCall
     * @param array<ParameterConverterBuilder> $parameterConverters
     */
    public function __construct(
        private InterfaceToCall $interfaceToCall,
        private array $parameterConverters,
    ) {}

    public function getInterfaceToCall(): InterfaceToCall
    {
        return $this->interfaceToCall;
    }

    /**
     * @return array<ParameterConverterBuilder>
     */
    public function getParameterConverters(): array
    {
        return $this->parameterConverters;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            EventSourcingHandlerMethod::class,
            [
                $this->interfaceToCall,
                $this->parameterConverters,
            ]
        );
    }
}