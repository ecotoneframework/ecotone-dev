<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Graph\StitchedFlowGraph;
use App\Graph\TraceHighlight;
use App\Snapshot\Snapshots;
use App\Trace\TraceLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FlowGraphController extends AbstractController
{
    private const OVERVIEW_ABOVE_SERVICES = 6;

    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly TraceLoader $traces,
        #[Autowire(param: 'pulse.graph_default_node_limit')] private readonly int $defaultNodeLimit,
    ) {
    }

    #[Route('/flow', name: 'flow_graph', methods: ['GET'])]
    public function flowGraph(Request $request): Response
    {
        $snapshots = $this->snapshots->viewAndRequestStale();
        $serviceFlows = ServiceFlows::of($snapshots);
        $traceId = trim((string) $request->query->get('trace', ''));
        $traceView = $traceId === '' ? null : $this->traces->load($traceId, $snapshots);
        $highlight = $traceView === null ? null : TraceHighlight::of($traceId, $traceView->trace, $serviceFlows->flows);
        $flows = $serviceFlows->flows;
        $available = array_map(fn (ServiceFlow $flow) => $flow->name, $flows);
        $requested = $request->query->all('services');
        $totalNodes = array_sum(array_map(fn (ServiceFlow $flow) => count($flow->graph->nodes()), $flows));
        $crossed = $highlight === null ? [] : array_values(array_intersect($available, $highlight->services));
        $narrowedByTrace = $requested === [] && $crossed !== [];
        $overview = $requested === [] && ! $narrowedByTrace && $request->query->get('view') !== 'detail' && ($request->query->get('view') === 'overview' || count($available) > self::OVERVIEW_ABOVE_SERVICES || ($totalNodes > $this->defaultNodeLimit && count($available) > 1));
        $shown = match (true) {
            $narrowedByTrace => $crossed,
            $requested === [] => $available,
            default => array_values(array_intersect($available, array_map('strval', $requested))),
        };

        $nodes = $overview ? StitchedFlowGraph::overviewDetails($flows) : StitchedFlowGraph::nodeDetails($flows, $shown);
        $nodes = array_map(fn (array $node): array => [...$node, 'href' => $overview
            ? $this->generateUrl('flow_graph', ['services' => [$node['service']]])
            : $this->generateUrl('dead_letters', ['service' => $node['service']])], $nodes);

        return $this->render('graph/flow.html.twig', [
            'overview' => $overview,
            'nodes' => $nodes,
            'overviewUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'overview', 'trace' => $traceId])),
            'detailUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'detail', 'trace' => $traceId])),
            'services' => $available,
            'shown' => $shown,
            'notices' => $serviceFlows->notices,
            'diagram' => $overview ? StitchedFlowGraph::overview($flows) : StitchedFlowGraph::toMermaid($flows, $shown, $highlight),
            'trace' => $highlight,
            'traceStates' => $traceView?->states ?? [],
            'traceEmpty' => $traceView !== null && $traceView->trace->isEmpty(),
            'waiting' => $traceView?->waiting ?? false,
        ]);
    }
}
