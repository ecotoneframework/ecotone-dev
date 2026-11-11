<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\FlowFocus;
use App\Graph\ServiceFlow;
use App\Graph\ServiceFlows;
use App\Graph\ShortLabel;
use App\Graph\StitchedFlowGraph;
use App\Graph\UnknownCatalogueNode;
use App\Settings\PulseSettings;
use App\Snapshot\ChannelDepth;
use App\Snapshot\Snapshots;
use App\Ui\CatalogueNode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogueNodeController extends AbstractController
{
    private const NEIGHBOURHOOD_HOPS = 2;
    private const RESULTS_LIMIT = 200;

    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly PulseSettings $settings,
    ) {
    }

    #[Route('/catalogue/{service}/node', name: 'catalogue_node', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function node(string $service, Request $request): Response
    {
        $snapshots = $this->snapshots->viewAndRequestStale();
        $serviceFlows = ServiceFlows::of($snapshots);
        $flows = $serviceFlows->flows;
        $flow = array_find($flows, static fn (ServiceFlow $candidate): bool => $candidate->name === $service);
        if ($flow === null) {
            throw UnknownCatalogueNode::ofService($service, array_map(static fn (ServiceFlow $candidate): string => $candidate->name, $flows));
        }
        $nodeId = $request->query->getString('id');
        if ($nodeId === '') {
            throw UnknownCatalogueNode::withoutId($service);
        }
        if (! $flow->graph->hasNode($nodeId)) {
            throw UnknownCatalogueNode::ofNode($service, $nodeId);
        }
        $neighbourhood = FlowFocus::neighbourhood($flows, $service, $nodeId, self::NEIGHBOURHOOD_HOPS);
        $shown = array_values(array_intersect(array_map(static fn (ServiceFlow $candidate): string => $candidate->name, $flows), $neighbourhood->services()));
        $links = array_map(fn (array $detail): array => [
            ...$detail,
            'href' => isset($detail['node']) ? $this->generateUrl('catalogue_node', ['service' => $detail['service'], 'id' => $detail['node']]) : $this->generateUrl('service_hub', ['service' => $detail['service']]),
            'title' => 'Open the page of ' . $detail['title'],
        ], StitchedFlowGraph::nodeDetails($flows, $shown, $neighbourhood));
        $depths = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service === $service) {
                $depths = ChannelDepth::of($snapshot, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds);
            }
        }

        return $this->render('graph/node.html.twig', [
            'node' => CatalogueNode::of($flows, $flow, $flow->graph->node($nodeId), $snapshots, $depths),
            'diagram' => StitchedFlowGraph::diagram($flows, $shown, null, null, $neighbourhood)->linked($links),
            'hops' => self::NEIGHBOURHOOD_HOPS,
            'notices' => $serviceFlows->notices,
        ]);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = trim($request->query->getString('q'));
        $snapshots = $this->snapshots->viewAndRequestStale();
        $flows = ServiceFlows::of($snapshots)->flows;
        $results = [];
        $words = array_values(array_filter(preg_split('/\s+/', $query) ?: []));
        if ($query !== '') {
            foreach ($flows as $flow) {
                foreach ($flow->graph->nodes() as $node) {
                    $exact = mb_strtolower(ShortLabel::of($node->label)) === mb_strtolower($query) || mb_strtolower($node->label) === mb_strtolower($query);
                    $named = [$node->label, $node->id];
                    foreach (['class', 'method', 'endpointId', 'routingKey', 'routingKeys', 'name', 'interface'] as $attribute) {
                        array_push($named, ...array_filter((array) ($node->attributes[$attribute] ?? []), is_string(...)));
                    }
                    $haystack = implode("\n", $named);
                    if ($exact || array_all($words, static fn (string $word): bool => mb_stripos($haystack, $word) !== false)) {
                        $results[] = ['service' => $flow->name, 'nodeId' => $node->id, 'kind' => $node->kind->value, 'label' => ShortLabel::of($node->label), 'fullLabel' => $node->label, 'exact' => $exact, 'named' => array_all($words, static fn (string $word): bool => mb_stripos(ShortLabel::of($node->label), $word) !== false)];
                    }
                }
            }
        }
        usort($results, static fn (array $one, array $other): int => [$other['exact'], $other['named'], $one['label'], $one['service']] <=> [$one['exact'], $one['named'], $other['label'], $other['service']]);
        $exactMatches = array_values(array_filter($results, static fn (array $result): bool => $result['exact']));
        if (count($results) === 1 || count($exactMatches) === 1) {
            return $this->redirectToRoute('catalogue_node', ['service' => $results[0]['service'], 'id' => $results[0]['nodeId']]);
        }

        return $this->render('graph/search.html.twig', [
            'query' => $query,
            'total' => count($results),
            'results' => array_slice($results, 0, self::RESULTS_LIMIT),
            'limit' => self::RESULTS_LIMIT,
            'answered' => $flows !== [],
        ]);
    }
}
