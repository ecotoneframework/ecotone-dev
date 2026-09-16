<?php

namespace Test\Ecotone\Messaging\Fixture\MessageConverter;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\Payload;
use stdClass;

/**
 * licence Apache-2.0
 */
interface FakeMessageConverterGatewayExample
{
    #[MessageGateway('requestChannel')]
    public function execute(#[Header('some')] array $some, #[Payload] string $amount): stdClass;
}
