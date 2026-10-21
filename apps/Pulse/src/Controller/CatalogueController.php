<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\MessageCatalogue;
use App\Graph\ServiceFlow;
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
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());
        $flows = array_values(array_filter(array_map(ServiceFlow::fromSnapshot(...), $snapshots)));

        return $this->render('graph/catalogue.html.twig', ['query' => $query, 'entries' => MessageCatalogue::search($flows, $query)]);
    }
}
