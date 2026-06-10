<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Counter;

use Ecotone\Messaging\Attribute\BusinessMethod;

/**
 * licence Apache-2.0
 */
interface CounterGateway
{
    #[BusinessMethod('counter.get')]
    public function get(): int;
}
