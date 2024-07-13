<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

/**
 * Interface WithRequiredReferenceNameList
 * @package Ecotone\Messaging\Attribute
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface WithRequiredReferenceNameList
{
    /**
     * @return string[]
     */
    public function getRequiredReferenceNameList(): iterable;
}
