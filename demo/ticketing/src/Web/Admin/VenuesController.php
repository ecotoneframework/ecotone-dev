<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Catalogue\CatalogueRefusal;
use App\Catalogue\CreateVenue;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VenuesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    #[Route('/admin/venues', name: 'admin_venues', methods: ['GET'])]
    public function list(): Response
    {
        return $this->venuesPage();
    }

    #[Route('/admin/venues', name: 'admin_venues_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $name = $request->request->getString('name');
        try {
            $this->commandBus->send(new CreateVenue(
                name: $name,
                sections: explode(',', $request->request->getString('sections')),
                rowsPerSection: $request->request->getInt('rowsPerSection'),
                seatsPerRow: $request->request->getInt('seatsPerRow'),
                vipRows: explode(',', $request->request->getString('vipRows')),
            ));
        } catch (CatalogueRefusal $refusal) {
            return $this->venuesPage($refusal->getMessage());
        }

        $this->addFlash('notice', sprintf('Venue "%s" created.', trim($name)));

        return $this->redirectToRoute('admin_venues');
    }

    private function venuesPage(?string $refusal = null): Response
    {
        return $this->render(
            'admin/venues.html.twig',
            ['venues' => $this->queryBus->sendWithRouting('venues.list'), 'refusal' => $refusal],
            new Response(status: $refusal === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }
}
