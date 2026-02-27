<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'projected_tickets')]
class ProjectedTicketEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'ticket_id', type: 'string', length: 255)]
    public string $ticketId;

    #[ORM\Column(name: 'status', type: 'string', length: 255)]
    public string $status;

    public function __construct(string $ticketId, string $status)
    {
        $this->ticketId = $ticketId;
        $this->status = $status;
    }
}
