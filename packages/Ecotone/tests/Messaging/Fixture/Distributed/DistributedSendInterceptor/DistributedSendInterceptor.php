<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Distributed\DistributedSendInterceptor;

use Ecotone\Api\Before;
use Ecotone\Api\DistributedBus;

final class DistributedSendInterceptor
{
    #[Before(pointcut: DistributedBus::class, changeHeaders: true)]
    public function addHeaders(): array
    {
        return [
            'extra' => '123a',
        ];
    }
}
