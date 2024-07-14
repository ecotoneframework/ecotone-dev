<?php

namespace Ecotone\JMSConverter;

use ArrayObject;

/**
 * licence Apache-2.0
 */
final class ArrayObjectConverter
{
    public function from(ArrayObject $arrayAccess): array
    {
        return $arrayAccess->getArrayCopy();
    }

    public function to(array $arrayAccess): ArrayObject
    {
        return new ArrayObject($arrayAccess);
    }
}
