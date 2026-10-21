<?php

declare(strict_types=1);

namespace App\Controller;

use App\Graph\ServiceFlows;
use App\Graph\TopologyReport;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TopologyController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings)
    {
    }

    #[Route('/topology', name: 'topology', methods: ['GET'])]
    public function topology(): Response
    {
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());
        $serviceFlows = ServiceFlows::of($snapshots);
        $report = TopologyReport::of($serviceFlows, array_keys($this->settings->serviceChannels), $this->settings->serviceName);

        return $this->render('graph/topology.html.twig', ['report' => $report, 'notices' => $serviceFlows->notices, 'diagram' => $report->toMermaid()]);
    }
}
