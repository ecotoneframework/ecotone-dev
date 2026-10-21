<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionOverview;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionsController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings)
    {
    }

    #[Route('/services/{service}/projections', name: 'service_projections', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function projections(string $service): Response
    {
        foreach ($this->snapshots->viewAndRequestStale() as $snapshot) {
            if ($snapshot->service !== $service) {
                continue;
            }

            $statuses = ProjectionOverview::of($snapshot, $this->settings->projectionStalledAfterSeconds);

            return $this->render('services/projections.html.twig', [
                'service' => $snapshot,
                'projections' => $statuses,
                'counts' => ProjectionOverview::counts($statuses),
                'stalledAfter' => $this->settings->projectionStalledAfterSeconds,
                'waiting' => Snapshots::needsAutoRefresh([$snapshot]),
            ]);
        }

        throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
    }
}
