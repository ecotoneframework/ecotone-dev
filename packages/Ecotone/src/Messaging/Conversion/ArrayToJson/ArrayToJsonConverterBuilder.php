<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\ArrayToJson;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\ConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class ArrayToJsonConverterBuilder
 * @package Ecotone\Messaging\Conversion\ArrayToJson
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ArrayToJsonConverterBuilder implements ConverterBuilder
{
    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): Converter
    {
        return new ArrayToJsonConverter();
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return new Definition(ArrayToJsonConverter::class);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }
}
