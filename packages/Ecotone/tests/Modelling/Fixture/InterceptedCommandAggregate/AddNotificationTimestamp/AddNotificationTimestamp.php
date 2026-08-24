<?php

namespace Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\AddNotificationTimestamp;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Interceptor\After;
use Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\Logger;

/**
 * licence Apache-2.0
 */
class AddNotificationTimestamp
{
    private $currentTime;

    #[CommandHandler('changeCurrentTime')]
    public function setTime(string $currentTime): void
    {
        $this->currentTime = $currentTime;
    }

    #[After(pointcut: Logger::class, changeHeaders: true)]
    public function add(array $events, array $metadata): array
    {
        return array_merge(
            $metadata,
            ['notificationTimestamp' => $this->currentTime]
        );
    }
}
