<?php

declare(strict_types=1);

namespace App\Web;

use Ecotone\Api\Gateway\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class TicketController extends AbstractController
{
    public function __construct(private readonly QueryBus $queryBus)
    {
    }

    #[Route('/tickets/{ticketNumber}', name: 'ticket', requirements: ['ticketNumber' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $ticketNumber): Response
    {
        $ticket = $this->queryBus->sendWithRouting('ticket.detail', $ticketNumber);
        if ($ticket === null) {
            return $this->render(
                'tickets/ticket.html.twig',
                ['ticket' => null, 'concert' => null, 'refusal' => sprintf("There is no ticket %s. Ticket numbers are issued once an order's seats are sold; open the ticket from the order page of the storefront.", $ticketNumber)],
                new Response(status: Response::HTTP_NOT_FOUND),
            );
        }

        return $this->render('tickets/ticket.html.twig', [
            'ticket' => $ticket,
            'concert' => $this->queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $ticket['concertId']]),
            'refusal' => null,
        ]);
    }
}
