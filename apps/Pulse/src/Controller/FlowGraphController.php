<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Graph\StitchedFlowGraph;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FlowGraphController extends AbstractController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        #[Autowire(param: 'pulse.graph_default_node_limit')] private readonly int $defaultNodeLimit,
    ) {
    }

    #[Route('/flow', name: 'flow_graph', methods: ['GET'])]
    public function flowGraph(Request $request): Response
    {
        $serviceFlows = ServiceFlows::of($this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale()));
        $flows = $serviceFlows->flows;
        $available = array_map(fn (ServiceFlow $flow) => $flow->name, $flows);
        $requested = $request->query->all('services');
        $totalNodes = array_sum(array_map(fn (ServiceFlow $flow) => count($flow->graph->nodes()), $flows));
        $narrowedByDefault = $requested === [] && $totalNodes > $this->defaultNodeLimit && count($available) > 1;
        $shown = match (true) {
            $narrowedByDefault => [$available[0]],
            $requested === [] => $available,
            default => array_values(array_intersect($available, array_map('strval', $requested))),
        };

        return $this->render('graph/flow.html.twig', [
            'services' => $available,
            'shown' => $shown,
            'notices' => $serviceFlows->notices,
            'narrowedMessage' => $narrowedByDefault ? sprintf('All services together have %d nodes, more than the %d that stay readable, so only %s is shown. Tick more services and press Show to add them.', $totalNodes, $this->defaultNodeLimit, $available[0]) : null,
            'diagram' => StitchedFlowGraph::toMermaid($flows, $shown),
        ]);
    }
}
