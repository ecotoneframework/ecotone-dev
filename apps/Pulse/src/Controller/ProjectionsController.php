<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionActionNotice;
use App\Projection\ProjectionOverview;
use App\Projection\ProjectionActionRequester;
use App\Projection\RebuildTrackings;
use App\Settings\PulseSettings;
use App\Snapshot\Refusals;
use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
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
        $this->snapshots->viewScopesAndRequestStale();
        $rows = array_values(array_filter($this->snapshots->scopes(), static fn (ServiceSnapshot $row): bool => $row->scope() === $service || $row->service === $service));
        if ($rows === []) {
            throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
        }

        $sections = [];
        foreach ($rows as $row) {
            $rebuilds = $this->trackings->of($row);
            $statuses = ProjectionOverview::of($row, $this->settings->projectionStalledAfterSeconds, $rebuilds);
            $sections[] = ['snapshot' => $row, 'projections' => $statuses, 'counts' => ProjectionOverview::counts($statuses), 'rebuilds' => $rebuilds];
        }

        $notice = ProjectionActionNotice::fromQuery($request->query->all());

        return $this->render('services/projections.html.twig', [
            'service' => $rows[0],
            'tenant' => ServiceScope::fromKey($service)->tenant,
            'sections' => $sections,
            'stalledAfter' => $this->settings->projectionStalledAfterSeconds,
            'trackingDays' => $this->settings->trackingDays,
            'notice' => $notice,
            'refusals' => $notice === null || $notice->isError() ? [] : Refusals::of($rows, ProjectionActionRequester::ROUTING_KEYS[$notice->action]),
            'waiting' => Snapshots::needsAutoRefresh($rows) || array_filter($sections, static fn (array $section): bool => $section['rebuilds']->waiting || $section['rebuilds']->anyRunning()) !== [],
        ]);
    }
}
