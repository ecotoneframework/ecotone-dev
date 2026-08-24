<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\IgnorePayload;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateNoInputChannelAndNoMessage
{
    #[Identifier]
    private string $id;

    #[CommandHandler]
    #[IgnorePayload]
    public function doAction(): void
    {
    }
}
