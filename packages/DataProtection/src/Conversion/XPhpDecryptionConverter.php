<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class XPhpDecryptionConverter extends AbstractDecryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $this->decrypt($source);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->acceptType($this->supportedType) && $sourceType->isIterable() && $sourceMediaType->isCompatibleWith(MediaType::createApplicationXPHP()) && $sourceMediaType->hasParameter('encrypted');
    }
}
