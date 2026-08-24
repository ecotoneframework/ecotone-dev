<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\CommandEventFlow;

use Ecotone\Api\BusinessMethod;
use Ecotone\Api\Headers;

interface CreateMerchantService
{
    #[BusinessMethod('create.merchant')]
    public function create(CreateMerchant $command, #[Headers] array $metadata): void;
}
