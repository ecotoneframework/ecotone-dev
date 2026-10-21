<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionOverview;
use App\Settings\PulseSettings;
use App\Snapshot\ServiceSnapshot;
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
        $this->snapshots->viewScopesAndRequestStale();
        $rows = array_values(array_filter($this->snapshots->scopes(), static fn (ServiceSnapshot $row): bool => $row->scope() === $service || $row->service === $service));
        if ($rows === []) {
            throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
        }

        $sections = [];
        foreach ($rows as $row) {
            $statuses = ProjectionOverview::of($row, $this->settings->projectionStalledAfterSeconds);
            $sections[] = ['snapshot' => $row, 'projections' => $statuses, 'counts' => ProjectionOverview::counts($statuses)];
        }

        return $this->render('services/projections.html.twig', [
            'service' => $rows[0],
            'sections' => $sections,
            'stalledAfter' => $this->settings->projectionStalledAfterSeconds,
            'waiting' => Snapshots::needsAutoRefresh($rows),
        ]);
    }
}
