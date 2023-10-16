<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\ObjectToSerialized;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\ConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class SerializingConverterBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class SerializingConverterBuilder implements ConverterBuilder
{
    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): Converter
    {
        return new SerializingConverter();
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(SerializingConverter::class);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }
}
