<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ServiceFlow;
use App\Graph\StitchedFlowGraph;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FlowGraphController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/flow', name: 'flow_graph', methods: ['GET'])]
    public function flowGraph(Request $request): Response
    {
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());
        $flows = array_values(array_filter(array_map(ServiceFlow::fromSnapshot(...), $snapshots)));
        $available = array_map(fn (ServiceFlow $flow) => $flow->pulseName, $flows);
        $requested = $request->query->all('services');
        $shown = $requested === [] ? $available : array_values(array_intersect($available, array_map('strval', $requested)));

        return $this->render('graph/flow.html.twig', [
            'services' => $available,
            'shown' => $shown,
            'diagram' => StitchedFlowGraph::toMermaid($flows, $shown),
        ]);
    }
}
