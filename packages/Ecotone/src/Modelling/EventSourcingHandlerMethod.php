<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

final class EventSourcingHandlerMethod
{
    /**
     * @param InterfaceToCall $interfaceToCall
     * @param array<ParameterConverter> $parameterConverters
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
     * @return array<ParameterConverter>
     */
    public function getParameterConverters(): array
    {
        return $this->parameterConverters;
    }
}