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
readonly class XMLDecryptionConverter implements Converter
{
    public function __construct(
        private Type $supportedType,
        private Key $encryptionKey,
        private array $sensitiveProperties,
        private array $scalarProperties,
    ) {
    }

    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        $data = XmlHelper::xmlToArray($source);

        foreach ($this->sensitiveProperties as $property) {
            if (! array_key_exists($property, $data)) {
                continue;
            }

            $data[$property] = Crypto::decrypt($data[$property], $this->encryptionKey);

            if (! in_array($property, $this->scalarProperties, true)) {
                $data[$property] = json_decode($data[$property], true);
            }
        }

        return XmlHelper::arrayToXml($data);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetType->acceptType($this->supportedType) && $sourceType->isString() && $sourceMediaType->isCompatibleWith(MediaType::createApplicationXml()) && $sourceMediaType->hasParameter('encrypted');
    }
}
