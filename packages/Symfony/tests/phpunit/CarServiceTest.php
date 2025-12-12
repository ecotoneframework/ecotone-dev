<?php

declare(strict_types=1);

namespace Test;

use Fixture\Car\CarService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CarServiceTest extends KernelTestCase
{
    private CarService $carService;

    protected function setUp(): void
    {
        self::bootKernel([
            'environment' => 'test',
        ]);
        $this->carService = self::getContainer()->get(CarService::class);
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    public function test_as_a_driver_i_drive_car(): void
    {
        // Given there is car
        // When I speed up to 100
        $this->carService->increaseSpeed(100);

        // Then there speed should be 100
        $this->assertEquals(100, $this->carService->getSpeed());
    }

    public function test_running_ecotone_lite_for_test(): void
    {
        // Given there is car
        // When I speed up to 100
        $this->carService->increaseSpeed(100);

        // Then there speed should be 100
        $this->assertEquals(100, $this->carService->getSpeed());
    }
}
