<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Attribute\Enterprise;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\TypeDescriptor;

final class EnterpriseModeDecider
{
    public function __construct(private bool $isEnterpriseModeDefault)
    {

    }

    public function isEnabledFor(InterfaceToCall $interfaceToCall): bool
    {
        if ($this->isEnterpriseModeDefault) {
            return true;
        }

        $type = TypeDescriptor::create(Enterprise::class);
        return $interfaceToCall->hasMethodAnnotation($type) || $interfaceToCall->hasClassAnnotation($type);
    }
}