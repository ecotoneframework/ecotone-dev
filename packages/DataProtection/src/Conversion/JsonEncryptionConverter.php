<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class JsonEncryptionConverter extends AbstractEncryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = json_decode($source, true);
        $data = $this->encrypt($data);

        return json_encode($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->acceptType($this->supportedType) && $targetType->isString() && $targetMediaType->isCompatibleWith(MediaType::createApplicationJson()) && $targetMediaType->hasParameter('encrypted');
    }
}
