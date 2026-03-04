<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class XMLDecryptionConverter extends AbstractDecryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = XmlHelper::xmlToArray($source);
        $data = $this->decrypt($data);

        return XmlHelper::arrayToXml($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->acceptType($this->supportedType) && $sourceType->isString() && $sourceMediaType->isCompatibleWith(MediaType::createApplicationXml()) && $sourceMediaType->hasParameter('encrypted');
    }
}
