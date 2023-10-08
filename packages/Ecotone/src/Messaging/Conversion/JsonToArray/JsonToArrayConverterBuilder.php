<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\JsonToArray;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\ConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class JsonToArrayConverterBuilder
 * @package Ecotone\Messaging\Conversion\JsonToArray
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class JsonToArrayConverterBuilder implements ConverterBuilder
{
    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): Converter
    {
        return new JsonToArrayConverter();
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return new Definition(JsonToArrayConverter::class);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }
}
