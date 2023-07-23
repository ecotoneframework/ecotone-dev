<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\FailureHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

final class ExampleFailureCommandHandler
{
    #[Asynchronous("async")]
    #[CommandHandler("handler.fail", endpointId: "failureHandler")]
    public function handle(): void
    {
        throw new \InvalidArgumentException("test");
    }
}