<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\FlowFocus;
use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Graph\ServiceScopeFilter;
use App\Graph\ShortLabel;
use App\Graph\StitchedFlowGraph;
use App\Graph\TraceHighlight;
use App\Graph\UnknownCollapse;
use App\Graph\UnknownFold;
use App\Snapshot\Snapshots;
use App\Trace\TraceLoader;
use Ecotone\Api\Messaging\Flow\FlowNode;
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
        $foldChannels = $this->foldsChannels($request->query->getString('fold'));
        $collapsed = $this->collapsedOf($available, array_map('strval', $request->query->all('collapse')));
        $crossedNodes = array_sum(array_map(fn (ServiceFlow $flow) => in_array($flow->name, $crossed, true) ? count($flow->graph->nodes()) : 0, $flows));
        $overview = $focus === null && $collapsed === [] && $requested === [] && $view !== 'detail' && match (true) {
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

        $carried = array_filter(['trace' => $traceId, 'service' => $scope->selected, 'collapse' => $collapsed, 'fold' => $foldChannels ? 'channels' : '']);
        $find = trim($request->query->getString('find'));
        $found = $find === '' ? [] : $this->found($flows, $find, $carried);
        if (count($found) === 1) {
            return $this->redirect($found[0]['href']);
        }

        $nodes = $overview ? StitchedFlowGraph::overviewDetails($flows) : StitchedFlowGraph::nodeDetails($flows, $shown, $focus, $collapsed);
        $nodes = array_map(fn (array $node): array => [...$node, 'href' => match (true) {
            $overview => $this->generateUrl('flow_graph', ['services' => [$node['service']]]),
            isset($node['collapsed']) => $this->collapseUrl($request, $collapsed, $node['service']),
            isset($node['node']) => $this->focusUrl($node['service'], $node['node'], $carried),
            default => $this->generateUrl('dead_letters', ['service' => $node['service']]),
        }], $nodes);

        return $this->render('graph/flow.html.twig', [
            'scope' => $scope,
            'find' => $find,
            'carried' => $carried,
            'found' => $found,
            'nodeNames' => $this->nodeNamesOf($flows),
            'focus' => $focus === null ? null : [
                'label' => ShortLabel::of($focus->node->label),
                'parameter' => $focus->service . ':' . $focus->node->id,
                'service' => $focus->service,
                'clearUrl' => $this->generateUrl('flow_graph', $carried),
                'upstream' => $this->stepsOf($focus->upstream, $carried),
                'downstream' => $this->stepsOf($focus->downstream, $carried),
            ],
            'overview' => $overview,
            'nodes' => $nodes,
            'overviewUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'overview', 'trace' => $traceId])),
            'detailUrl' => $this->generateUrl('flow_graph', array_filter(['view' => 'detail', 'trace' => $traceId])),
            'services' => $available,
            'shown' => $shown,
            'collapsed' => $collapsed,
            'foldChannels' => $foldChannels,
            'foldUrl' => $this->generateUrl('flow_graph', array_filter([...$request->query->all(), 'fold' => $foldChannels ? '' : 'channels'])),
            'collapseUrls' => array_combine($shown, array_map(fn (string $service): string => $this->collapseUrl($request, $collapsed, $service), $shown)),
            'notices' => $serviceFlows->notices,
            'diagram' => $overview ? StitchedFlowGraph::overview($flows, $highlight) : StitchedFlowGraph::toMermaid($flows, $shown, $highlight, $scope, $focus, $collapsed, $foldChannels),
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

    /**
     * @param list<array{service: string, node: FlowNode}> $steps
     * @param array<string, string|list<string>> $carried
     * @return list<array{label: string, kind: string, service: string, href: string}>
     */
    private function stepsOf(array $steps, array $carried): array
    {
        return array_map(fn (array $step): array => [
            'label' => ShortLabel::of($step['node']->label),
            'kind' => $step['node']->kind->value,
            'service' => $step['service'],
            'href' => $this->focusUrl($step['service'], $step['node']->id, $carried),
        ], $steps);
    }

    /**
     * @param array<string, string|list<string>> $carried
     */
    private function focusUrl(string $service, string $nodeId, array $carried): string
    {
        return $this->generateUrl('flow_graph', ['focus' => $service . ':' . $nodeId, ...$carried]);
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param array<string, string|list<string>> $carried
     * @return list<array{label: string, kind: string, service: string, href: string}>
     */
    private function found(array $flows, string $find, array $carried): array
    {
        $exact = [];
        $containing = [];
        foreach ($flows as $flow) {
            foreach ($flow->graph->nodes() as $node) {
                if (mb_strtolower(ShortLabel::of($node->label)) === mb_strtolower($find) || mb_strtolower($node->label) === mb_strtolower($find)) {
                    $exact[] = ['service' => $flow->name, 'node' => $node];
                } elseif (mb_stripos(ShortLabel::of($node->label), $find) !== false || mb_stripos($node->label, $find) !== false) {
                    $containing[] = ['service' => $flow->name, 'node' => $node];
                }
            }
        }
        $steps = $this->stepsOf($exact === [] ? $containing : $exact, $carried);
        usort($steps, fn (array $one, array $other): int => [$one['label'], $one['service']] <=> [$other['label'], $other['service']]);

        return $steps;
    }

    /**
     * @param list<ServiceFlow> $flows
     * @return list<string>
     */
    private function nodeNamesOf(array $flows): array
    {
        $names = [];
        foreach ($flows as $flow) {
            foreach ($flow->graph->nodes() as $node) {
                $names[ShortLabel::of($node->label)] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return array_map('strval', $names);
    }

    /**
     * @param list<string> $collapsed
     */
    private function collapseUrl(Request $request, array $collapsed, string $service): string
    {
        $toggled = in_array($service, $collapsed, true) ? array_values(array_diff($collapsed, [$service])) : [...$collapsed, $service];

        return $this->generateUrl('flow_graph', array_filter([...$request->query->all(), 'collapse' => $toggled]));
    }

    /**
     * @param list<string> $available
     * @param list<string> $requested
     * @return list<string>
     */
    private function collapsedOf(array $available, array $requested): array
    {
        foreach ($requested as $service) {
            if (! in_array($service, $available, true)) {
                throw new UnknownCollapse($service, $available);
            }
        }

        return array_values(array_intersect($available, $requested));
    }

    private function foldsChannels(string $fold): bool
    {
        if ($fold !== '' && $fold !== 'channels') {
            throw new UnknownFold($fold);
        }

        return $fold === 'channels';
    }
}
