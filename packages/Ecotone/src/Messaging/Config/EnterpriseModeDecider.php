<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Attribute\Enterprise;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\TypeDescriptor;

/**
 * licence Enterprise
 *
 * This class is responsible for deciding whatever enterprise mode is enabled.
 * To make use of Enterprise features, you need to have Enterprise license.
 */
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