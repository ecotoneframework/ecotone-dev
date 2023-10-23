<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\JsonToArray;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Class JsonToArrayConverterBuilder
 * @package Ecotone\Messaging\Conversion\JsonToArray
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class JsonToArrayConverterBuilder implements CompilableBuilder
{
    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(JsonToArrayConverter::class);
    }
}
