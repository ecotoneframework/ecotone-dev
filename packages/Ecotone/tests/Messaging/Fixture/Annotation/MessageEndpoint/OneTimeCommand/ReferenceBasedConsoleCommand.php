<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\OneTimeCommand;

use Ecotone\Api\ClassReference;
use Ecotone\Api\ConsoleCommand;
use stdClass;

#[ClassReference('consoleCommand')]
/**
 * licence Apache-2.0
 */
class ReferenceBasedConsoleCommand
{
    public function __construct(stdClass $class)
    {
    }

    #[ConsoleCommand('doSomething')]
    public function execute(): void
    {
    }
}
