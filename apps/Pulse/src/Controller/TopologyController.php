<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ServiceFlows;
use App\Graph\ServiceScopeFilter;
use App\Graph\TopologyReport;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TopologyController extends AbstractController
{
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

        return $this->render('graph/topology.html.twig', ['scope' => $scope, 'report' => $report, 'notices' => $serviceFlows->notices, 'diagram' => $report->toMermaid()]);
    }
}
