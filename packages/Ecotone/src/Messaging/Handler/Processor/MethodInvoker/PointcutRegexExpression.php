<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\InterfaceToCall;

class PointcutRegexExpression implements PointcutExpression
{
    public function __construct(private string $regex)
    {
        $this->regex = '#' . str_replace('*', '.*', $this->regex) . '#';
        $this->regex = str_replace('\\', '\\\\', $this->regex);
    }
    public function doesItCutWith(array $endpointAnnotations, InterfaceToCall $interfaceToCall): bool
    {
        return preg_match($this->regex, $interfaceToCall->getInterfaceName()) === 1;
    }
}