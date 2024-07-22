<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\InterfaceToCall;

class PointcutBoolExpression implements PointcutExpression
{
    public function __construct(private bool $boolean)
    {
    }

    public function doesItCutWith(array $endpointAnnotations, InterfaceToCall $interfaceToCall): bool
    {
        return $this->boolean;
    }
}