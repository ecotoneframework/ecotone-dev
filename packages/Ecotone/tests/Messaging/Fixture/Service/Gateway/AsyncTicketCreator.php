<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\MessageGateway;

/**
 * licence Enterprise
 */
interface AsyncTicketCreator
{
    #[Asynchronous('async')]
    #[MessageGateway('create')]
    public function create($data);

    #[Asynchronous('async')]
    #[MessageGateway('proxy')]
    public function proxy($data);
}
