<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ConsoleParameterOption;

final readonly class ExampleCommand
{
    #[ConsoleCommand('app:example-command')]
    public function execute(
        string                           $name, // argument
        array                            $types, // option
        #[ConsoleParameterOption] string $selection = 'default' // array of options
    ): void
    {
        echo "Hello, {$name}! You have selected {$selection} and " . implode(',', $types) . " types.\n";
    }
}