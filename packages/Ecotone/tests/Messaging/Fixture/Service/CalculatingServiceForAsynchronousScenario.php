<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\Before;
use Ecotone\Api\CommandHandler;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;

/**
 * Class CalculatingService
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class CalculatingServiceForAsynchronousScenario
{
    /**
     * @var int
     */
    private $secondValueForMathOperations;

    private $lastResult;

    /**
     * @param int $secondValueForMathOperations
     * @return CalculatingService
     */
    public static function create(int $secondValueForMathOperations): self
    {
        $calculatingService = new self();
        $calculatingService->secondValueForMathOperations = $secondValueForMathOperations;

        return $calculatingService;
    }

    #[Asynchronous('asyncOne')]
    #[CommandHandler('getResultOne', endpointId: 'getResultOneEndpoint')]
    public function resultOne(int $amount): int
    {
        $this->lastResult = $amount;
        return $amount;
    }

    #[Asynchronous('asyncTwo')]
    #[CommandHandler('getResultTwo', endpointId: 'getResultTwoEndpoint')]
    public function resultTwo(int $amount): int
    {
        $this->lastResult = $amount;
        return $amount;
    }

    #[Before(pointcut: AsynchronousRunningEndpoint::class)]
    public function multiply(int $amount): int
    {
        return $amount * $this->secondValueForMathOperations;
    }

    /**
     * @return mixed
     */
    public function getLastResult()
    {
        return $this->lastResult;
    }
}
