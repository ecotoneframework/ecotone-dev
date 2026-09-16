<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Messaging\Transaction\Transactional;

#[Transactional(['transactionFactory'])]
/**
 * licence Apache-2.0
 */
interface TransactionalInterceptorOnGatewayClassExample
{
    #[MessageGateway('requestChannel')]
    public function invoke(): void;
}
