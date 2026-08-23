<?php

namespace Test\Ecotone\Modelling\Fixture\InterceptingAggregate;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Interceptor\Around;
use Ecotone\Api\Attribute\Interceptor\Before;

/**
 * licence Apache-2.0
 */
class AddCurrentUserId
{
    private ?string $userId = null;

    #[CommandHandler('addCurrentUserId')]
    public function setCurrentUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    #[Before(pointcut: Basket::class)]
    public function addCurrentUserId(array $payload): array
    {
        return array_merge($payload, ['userId' => $this->userId]);
    }

    #[Around(pointcut: Basket::class)]
    public function logAction(?Basket $basket): void
    {
        $logged = true;
        //        do some logging
    }
}
