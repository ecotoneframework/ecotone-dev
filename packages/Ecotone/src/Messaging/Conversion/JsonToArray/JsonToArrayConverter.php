<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\JsonToArray;

use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\TypeDescriptor;

use function json_decode;

/**
 * Class JsonToArrayConverter
 * @package Ecotone\Messaging\Conversion\JsonToArray
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class JsonToArrayConverter implements Converter
{
    /**
     * @inheritDoc
     */
    public function convert($source, TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType)
    {
        return json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     */
    public function matches(TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType): bool
    {
        return
            $sourceType->equals(TypeDescriptor::createStringType())
            && $sourceMediaType->isCompatibleWithParsed(MediaType::APPLICATION_JSON)
            && $targetType->isArrayButNotClassBasedCollection()
            && $targetMediaType->isCompatibleWithParsed(MediaType::APPLICATION_X_PHP);
    }
}
