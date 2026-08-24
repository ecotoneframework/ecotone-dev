<?php

namespace Test\Ecotone\Messaging\Fixture\MessageConverter;

use Ecotone\Api\Header;
use Ecotone\Api\MessageGateway;
use Ecotone\Api\Payload;
use stdClass;

/**
 * licence Apache-2.0
 */
interface FakeMessageConverterGatewayExample
{
    #[MessageGateway('requestChannel')]
    public function execute(#[Header('some')] array $some, #[Payload] string $amount): stdClass;
}
