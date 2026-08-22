<?php

namespace Test\Ecotone\Modelling\Fixture\Renter;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\LazyRepositoryBuilder;
use Ecotone\Modelling\StateStoredRepository;

/**
 * licence Apache-2.0
 */
class AppointmentRepositoryBuilder implements LazyRepositoryBuilder
{
    /**
     * @var Appointment[]
     */
    private $appointments = [];

    private function __construct(array $appointments)
    {
        /** @var Appointment $appointment */
        foreach ($appointments as $appointment) {
            $this->appointments[$appointment->getAppointmentId()] = $appointment;
        }
    }

    public static function createEmpty(): self
    {
        return new self([]);
    }

    public static function createWith(array $appointments): self
    {
        return new self($appointments);
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return $aggregateClassName === Appointment::class;
    }

    public function build(): EventSourcedRepository|StateStoredRepository
    {
        return AppointmentStateStoredRepository::createWith($this->appointments);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(AppointmentStateStoredRepository::class, [$this->appointments], 'createWith');
    }
}
