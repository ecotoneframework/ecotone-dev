<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\MessageCatalogue;
use App\Graph\ServiceFlows;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogueController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/catalogue', name: 'catalogue', methods: ['GET'])]
    public function catalogue(Request $request): Response
    {
        $query = $request->query->getString('q');
        $service = $request->query->getString('service');
        $snapshots = $this->snapshots->viewAndRequestStale();
        $serviceFlows = ServiceFlows::of($snapshots);

        return $this->render('graph/catalogue.html.twig', [
            'query' => $query,
            'service' => $service,
            'services' => MessageCatalogue::services($serviceFlows->flows),
            'notices' => $serviceFlows->notices,
            'groups' => MessageCatalogue::groups($serviceFlows->flows, $query, $service),
        ]);
    }
}
