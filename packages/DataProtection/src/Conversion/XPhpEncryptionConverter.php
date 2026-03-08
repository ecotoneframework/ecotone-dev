<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class XPhpEncryptionConverter extends AbstractEncryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return $this->encrypt($source);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->acceptType($this->supportedType) && $targetType->isIterable() && $targetMediaType->isCompatibleWith(MediaType::createApplicationXPHP()) && $targetMediaType->hasParameter('encrypted');
    }
}
