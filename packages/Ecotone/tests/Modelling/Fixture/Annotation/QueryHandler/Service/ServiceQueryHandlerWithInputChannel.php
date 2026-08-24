<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service;

use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
class ServiceQueryHandlerWithInputChannel
{
    #[QueryHandler('execute', 'queryHandler')]
    public function execute(): int
    {
    }
}
