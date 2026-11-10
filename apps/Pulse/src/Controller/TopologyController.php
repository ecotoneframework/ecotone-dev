<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\Mismatch;
use App\Graph\ServiceFlows;
use App\Graph\ServiceScopeFilter;
use App\Graph\TopologyReport;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use App\Ui\TopologySummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TopologyController extends AbstractController
{
    private const EVENT_MAPPING_DOCS = 'https://docs.ecotone.tech/modelling/microservices-php/distributed-bus/distributed-bus-with-service-map/configuration#register-service-map-for-publishing';

    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings)
    {
    }

    #[Route('/topology', name: 'topology', methods: ['GET'])]
    public function topology(Request $request): Response
    {
        $snapshots = $this->snapshots->viewAndRequestStale();
        $serviceFlows = ServiceFlows::of($snapshots);
        $scope = ServiceScopeFilter::of($serviceFlows->flows, $request->query->getString('service'));
        $report = TopologyReport::of($serviceFlows, array_keys($this->settings->serviceChannels), $this->settings->serviceName);
        $report = $scope->isNarrowed() ? $report->narrowedTo($scope) : $report;
        $summary = TopologySummary::of($report, $serviceFlows->flows !== [], $serviceFlows->notices);
        $problems = array_map(fn (array $problem): array => [
            'kind' => $problem['kind'],
            'status' => $problem['status'],
            'text' => $problem['text'],
            'where' => $problem['where'],
            'evidence' => $problem['evidence'],
            'fix' => $problem['fix'],
            'concept' => $problem['concept'],
            'action' => $this->actionFor($problem['mismatch']),
        ], $summary->problems);

        return $this->render('graph/topology.html.twig', [
            'scope' => $scope,
            'report' => $report,
            'summary' => $summary,
            'problems' => $problems,
            'notices' => $serviceFlows->notices,
            'diagram' => $report->toMermaid(),
        ]);
    }

    /**
     * @return array{label: string, href: string}
     */
    private function actionFor(Mismatch $mismatch): array
    {
        [$from, $subject] = [$mismatch->where[0], $mismatch->where[1] ?? $mismatch->where[0]];

        return match ($mismatch->kind) {
            'unconfigured-command-target' => ['label' => "Connect {$subject} to Pulse", 'href' => $this->generateUrl('connect', ['service' => $subject])],
            'silent-command-target' => ['label' => "Diagnose {$subject}", 'href' => $this->generateUrl('service_hub', ['service' => $subject])],
            'unsubscribed-event' => ['label' => "Find {$subject} on the flow graph", 'href' => $this->generateUrl('flow_graph', ['find' => $subject])],
            'unpublished-subscription' => ['label' => 'How withEventMapping() works', 'href' => self::EVENT_MAPPING_DOCS],
            default => ['label' => "Open the sends of {$from} on the flow graph", 'href' => $this->generateUrl('flow_graph', ['service' => $from])],
        };
    }
}
