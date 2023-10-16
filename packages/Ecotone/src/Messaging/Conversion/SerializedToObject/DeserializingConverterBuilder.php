<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\SerializedToObject;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Class DeserializingConverterBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class DeserializingConverterBuilder implements CompilableBuilder
{
    /**
     * @inheritDoc
     */
    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(DeserializingConverter::class);
    }
}
