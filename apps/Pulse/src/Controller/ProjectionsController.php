<?php

declare(strict_types=1);

namespace App\Controller;

use App\Projection\ProjectionActionNotice;
use App\Projection\ProjectionActionRequester;
use App\Projection\ProjectionOverview;
use App\Projection\RebuildTrackings;
use App\Settings\PulseSettings;
use App\Snapshot\Refusals;
use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Ui\ProjectionList;
use App\Ui\ServiceOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectionsController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings, private readonly RebuildTrackings $trackings, private readonly ServiceOverview $overview)
    {
    }

    #[Route('/services/{service}/projections', name: 'service_projections', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function projections(string $service, Request $request): Response
    {
        $scope = ServiceScope::fromKey($service);
        $tenant = $scope->tenant ?? ($request->query->getString('tenant') ?: null);
        $scopes = $this->snapshots->viewScopesAndRequestStale();
        $main = $this->serviceOf($scope->service);
        $rows = array_values(array_filter($scopes, static fn (ServiceSnapshot $row): bool => $row->service === $scope->service && ($tenant === null || $row->tenant === $tenant)));
        if ($main === null || $rows === []) {
            throw $this->createNotFoundException($main === null
                ? sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $scope->service, implode(',', [...array_keys($this->settings->routes), $scope->service]))
                : sprintf('Tenant "%s" is not advertised by service "%s". Choose one of: %s, or open /services/%s/projections without a tenant.', $tenant, $scope->service, $main->tenants === [] ? 'none, it advertises no tenants' : implode(', ', $main->tenants), $scope->service));
        }

        $lists = [];
        foreach ($rows as $row) {
            $rebuilds = $this->trackings->of($row);
            $lists[] = ProjectionList::of($row, ProjectionOverview::of($row, $this->settings->projectionStalledAfterSeconds, $rebuilds), $rebuilds, $this->settings->projectionStalledAfterSeconds, $this->settings->trackingDays);
        }

        $notice = ProjectionActionNotice::fromQuery($request->query->all());
        $focus = $tenant !== null ? $rows[0] : ($main->isMultiTenant() ? null : $main);

        return $this->render('services/projections.html.twig', [
            'service' => $main,
            'tenant' => $tenant,
            'tabs' => $this->overview->tabRoutes($main, $tenant),
            'failureCount' => $focus?->deadLetterTotal(),
            'lists' => $lists,
            'verdict' => ProjectionList::verdictOf($main->service, $lists),
            'verdictReason' => ProjectionList::verdictReasonOf($lists),
            'verdictAction' => ProjectionList::verdictActionOf($lists),
            'stalledAfter' => $this->settings->projectionStalledAfterSeconds,
            'notice' => $notice,
            'refusals' => $notice === null || $notice->isError() ? [] : Refusals::of($rows, ProjectionActionRequester::ROUTING_KEYS[$notice->action]),
            'waiting' => Snapshots::needsAutoRefresh($rows) || array_filter($lists, static fn (ProjectionList $list): bool => $list->rebuilds->waiting || $list->rebuilds->anyRunning()) !== [],
        ]);
    }

    private function serviceOf(string $service): ?ServiceSnapshot
    {
        foreach ($this->snapshots->all() as $snapshot) {
            if ($snapshot->service === $service) {
                return $snapshot;
            }
        }

        return null;
    }
}
