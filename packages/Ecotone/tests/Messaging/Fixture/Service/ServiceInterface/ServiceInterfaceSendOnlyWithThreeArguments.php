<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

/**
 * Interface ServiceInterface
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ServiceInterfaceSendOnlyWithThreeArguments
{
    public function calculate(int $number, int $multiplyBy, float $percentage): void;
}
