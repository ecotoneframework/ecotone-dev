<?php

namespace Fixture\Car;

use Ecotone\Api\InternalHandler;

/**
 * licence Apache-2.0
 */
class Car
{
    /**
     * @var int
     */
    private $speed = 0;

    #[InternalHandler(IncreaseSpeedGateway::CHANNEL_NAME)]
    public function increaseSpeed(int $amount): void
    {
        $this->speed += $amount;
    }

    #[InternalHandler(StopGateway::CHANNEL_NAME)]
    public function stop(): void
    {
        $this->speed = 0;
    }

    #[InternalHandler(GetSpeedGateway::CHANNEL_NAME)]
    public function getCurrentSpeed(): int
    {
        return $this->speed;
    }
}
