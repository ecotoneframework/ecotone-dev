<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\ModuleConfiguration;

use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Messaging\Attribute\ServiceContext;
use stdClass;

/**
 * licence Apache-2.0
 */
class ExampleModuleExtensionWithVariableConfiguration
{
    #[ServiceContext]
    public function extensionObject(string $name, #[ConfigurationVariable('lastName')] string $secondName): stdClass
    {
        $stdClass = new stdClass();
        $stdClass->name = 'johny';
        $stdClass->surname = 'bravo';

        return $stdClass;
    }
}
