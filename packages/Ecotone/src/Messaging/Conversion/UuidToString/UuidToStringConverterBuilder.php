<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\UuidToString;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\ConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class UuidToStringConverterBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class UuidToStringConverterBuilder implements ConverterBuilder
{
    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): Converter
    {
        return new UuidToStringConverter();
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(UuidToStringConverter::class);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }
}
