<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Router;

use Ecotone\Api\Payload;
use Ecotone\Api\Router;

/**
 * licence Apache-2.0
 */
class RouterWithNoResolutionRequiredExample
{
    #[Router('inputChannel', 'some-id', false)]
    public function route(#[Payload] $content): string
    {
        return 'outputChannel';
    }
}
