<?php

namespace Ecotone\JMSConverter;

final class ArrayObjectConverter
{
    public function from(\ArrayObject $arrayAccess): array
    {
        return $arrayAccess->getArrayCopy();
    }

    public function to(array $arrayAccess): \ArrayObject
    {
        return new \ArrayObject($arrayAccess);
    }
}