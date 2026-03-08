<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class JsonDecryptionConverter extends AbstractDecryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = json_decode($source, true);
        $data = $this->decrypt($data);

        return json_encode($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->acceptType($this->supportedType) && $sourceType->isString() && $sourceMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $sourceMediaType->hasParameter('encrypted');
    }
}
