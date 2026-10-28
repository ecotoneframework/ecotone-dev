<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\FlowFocus;
use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Graph\ServiceScopeFilter;
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
        $scope = ServiceScopeFilter::of($flows, $request->query->getString('service'));
        $requested = $scope->isNarrowed() ? $scope->visible() : $request->query->all('services');
        $totalNodes = array_sum(array_map(fn (ServiceFlow $flow) => count($flow->graph->nodes()), $flows));
        $crossed = $highlight === null ? [] : array_values(array_intersect($available, $highlight->services));
        $narrowedByTrace = $requested === [] && $crossed !== [];
        $view = (string) $request->query->get('view', '');
        $focus = $this->focusOf($flows, $request->query->getString('focus'));
        $crossedNodes = array_sum(array_map(fn (ServiceFlow $flow) => in_array($flow->name, $crossed, true) ? count($flow->graph->nodes()) : 0, $flows));
        $overview = $focus === null && $requested === [] && $view !== 'detail' && match (true) {
            $view === 'overview' => true,
            $narrowedByTrace => $crossedNodes > $this->defaultNodeLimit,
            default => count($available) > self::OVERVIEW_ABOVE_SERVICES || ($totalNodes > $this->defaultNodeLimit && count($available) > 1),
        };
        $shown = match (true) {
            $focus !== null => array_values(array_intersect($available, $focus->services())),
            $narrowedByTrace && ! $overview => $crossed,
            $requested === [] => $available,
            default => array_values(array_intersect($available, array_map('strval', $requested))),
        };

        $nodes = $overview ? StitchedFlowGraph::overviewDetails($flows) : StitchedFlowGraph::nodeDetails($flows, $shown, $focus);
        $nodes = array_map(fn (array $node): array => [...$node, 'href' => match (true) {
            $overview => $this->generateUrl('flow_graph', ['services' => [$node['service']]]),
            isset($node['node']) => $this->generateUrl('flow_graph', array_filter(['focus' => $node['service'] . ':' . $node['node'], 'trace' => $traceId])),
            default => $this->generateUrl('dead_letters', ['service' => $node['service']]),
        }], $nodes);

        return $this->render('graph/flow.html.twig', [
            'scope' => $scope,
            'overview' => $overview,
            'nodes' => $nodes,
            'overviewUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'overview', 'trace' => $traceId])),
            'detailUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'detail', 'trace' => $traceId])),
            'services' => $available,
            'shown' => $shown,
            'notices' => $serviceFlows->notices,
            'diagram' => $overview ? StitchedFlowGraph::overview($flows, $highlight) : StitchedFlowGraph::toMermaid($flows, $shown, $highlight, $scope, $focus),
            'trace' => $highlight,
            'traceStates' => $traceView?->states ?? [],
            'traceEmpty' => $traceView !== null && $traceView->trace->isEmpty(),
            'waiting' => $traceView?->waiting ?? false,
        ]);
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    private function focusOf(array $flows, string $requested): ?FlowFocus
    {
        if ($requested === '') {
            return null;
        }
        [$service, $nodeId] = explode(':', $requested, 2) + [1 => ''];

        return FlowFocus::of($flows, $service, $nodeId);
    }
}
