<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion\StringToUuid;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Class StringToUuidConverterBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class StringToUuidConverterBuilder implements CompilableBuilder
{
    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(StringToUuidConverter::class);
    }
}
