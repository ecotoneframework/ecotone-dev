<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

use Ecotone\Messaging\Handler\TypeDescriptor;

/**
 * Interface Converter
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface Converter
{
    public function convert($source, TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType);


    public function matches(TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType): bool;
}
