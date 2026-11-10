<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\MessageCatalogue;
use App\Graph\ServiceFlows;
use App\Graph\ServiceScopeFilter;
use App\Snapshot\Snapshots;
use App\Ui\CatalogueSummary;
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
        $snapshots = $this->snapshots->viewAndRequestStale();
        $serviceFlows = ServiceFlows::of($snapshots);
        $scope = ServiceScopeFilter::of($serviceFlows->flows, $request->query->getString('service'));
        $known = array_sum(array_map(static fn (array $group): int => count($group['entries']), MessageCatalogue::groups($serviceFlows->flows, '', $scope)));
        $summary = CatalogueSummary::of(
            MessageCatalogue::groups($serviceFlows->flows, $query, $scope),
            $known,
            $snapshots,
            $serviceFlows->flows !== [],
            $serviceFlows->notices,
            $query,
            $request->query->getString('kind'),
            $scope->selected,
        );

        return $this->render('graph/catalogue.html.twig', [
            'query' => $query,
            'scope' => $scope,
            'notices' => $serviceFlows->notices,
            'summary' => $summary,
        ]);
    }
}
