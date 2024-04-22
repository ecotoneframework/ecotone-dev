<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\InterfaceToCall;

final class EventSourcingHandlerMethod
{
    public function __construct(
        private InterfaceToCall $interfaceToCall,
        private array $parameterConverters,
    ) {}

    public function getInterfaceToCall(): InterfaceToCall
    {
        return $this->interfaceToCall;
    }

    public function getParameterConverters(): array
    {
        return $this->parameterConverters;
    }
}