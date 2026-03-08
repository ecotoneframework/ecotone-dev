<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Enterprise
 */
class XMLEncryptionConverter extends AbstractEncryptionConverter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = XmlHelper::xmlToArray($source);
        $data = $this->encrypt($data);

        return XmlHelper::arrayToXml($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->acceptType($this->supportedType) && $targetType->isString() && $targetMediaType->isCompatibleWith(MediaType::createApplicationXml()) && $targetMediaType->hasParameter('encrypted');
    }
}
