<?php

namespace Test\Ecotone\Modelling\Fixture\Ticket;

/**
 * licence Apache-2.0
 */
class WorkerAssignationFailedEvent
{
    /**
     * @var string
     */
    private $ticketId;
    /**
     * @var string
     */
    private $assignedWorkerId;

    /**
     * AssignationFailed constructor.
     * @param string $ticketId
     * @param string $assignedWorkerId
     */
    public function __construct(string $ticketId, string $assignedWorkerId)
    {
        $this->ticketId = $ticketId;
        $this->assignedWorkerId = $assignedWorkerId;
    }
}
