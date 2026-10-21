<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionOverview;
use App\Projection\RebuildNotice;
use App\Projection\RebuildTrackings;
use App\Settings\PulseSettings;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionsController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings, private readonly RebuildTrackings $trackings)
    {
    }

    #[Route('/services/{service}/projections', name: 'service_projections', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function projections(string $service, Request $request): Response
    {
        foreach ($this->snapshots->viewAndRequestStale() as $snapshot) {
            if ($snapshot->service !== $service) {
                continue;
            }

            $rebuilds = $this->trackings->of($snapshot);
            $statuses = ProjectionOverview::of($snapshot, $this->settings->projectionStalledAfterSeconds, $rebuilds);

            return $this->render('services/projections.html.twig', [
                'service' => $snapshot,
                'projections' => $statuses,
                'counts' => ProjectionOverview::counts($statuses),
                'stalledAfter' => $this->settings->projectionStalledAfterSeconds,
                'rebuilds' => $rebuilds,
                'trackingDays' => $this->settings->trackingDays,
                'notice' => RebuildNotice::fromQuery($request->query->get('rebuild'), $request->query->get('projection')),
                'waiting' => Snapshots::needsAutoRefresh([$snapshot]) || $rebuilds->waiting || $rebuilds->anyRunning(),
            ]);
        }

        throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
    }
}
