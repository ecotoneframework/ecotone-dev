<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connect\ConnectSnippets;
use App\Doctor\Doctor;
use App\Doctor\DoctorCheck;
use App\Home\ProblemBoard;
use App\Projection\ProjectionOverview;
use App\Settings\PulseSettings;
use App\Setup\SetupStatus;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Trace\FlowTypeOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ServiceHubController extends AbstractController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly PulseSettings $settings,
        private readonly Doctor $doctor,
        private readonly ConnectSnippets $snippets,
        private readonly ProblemBoard $board,
    ) {
    }

    #[Route('/services/{service}', name: 'service_hub', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function hub(string $service, Request $request): Response
    {
        $snapshot = $this->snapshotOf($this->snapshots->viewAndRequestStale(), $service);
        if ($snapshot === null) {
            throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
        }

        $tenant = $request->query->getString('tenant');
        if ($tenant !== '' && ! in_array($tenant, $snapshot->tenants, true)) {
            throw $this->createNotFoundException($snapshot->tenants === []
                ? sprintf('Service "%s" advertises no tenants, so there is no tenant "%s" to show. Open /services/%s without the tenant parameter.', $service, $tenant, $service)
                : sprintf('Tenant "%s" is not advertised by service "%s". Choose one of: %s.', $tenant, $service, implode(', ', $snapshot->tenants)));
        }

        $failing = in_array($snapshot->status->value, ['unreachable', 'channel_missing'], true);
        $flowTypes = FlowTypeOverview::of([$snapshot])[0];
        $tenantRows = array_values(array_filter($this->snapshots->scopes(), static fn (ServiceSnapshot $row): bool => $row->service === $service && $row->tenant !== null));
        $projections = ProjectionOverview::of($snapshot, $this->settings->projectionStalledAfterSeconds);

        $focus = $tenant !== '' ? $this->scopeOf($tenantRows, $tenant) : ($snapshot->isMultiTenant() ? null : $snapshot);
        $everyScope = $this->snapshots->scopes();
        $tiles = $this->board->tiles($focus === null ? $tenantRows : [$focus], $everyScope);
        $focusProjections = $focus === null ? [] : ProjectionOverview::of($focus, $this->settings->projectionStalledAfterSeconds);
        $focusFlowTypes = $focus === null ? ['tracing' => false, 'flowTypes' => []] : FlowTypeOverview::of([$focus])[0];

        return $this->render('services/hub.html.twig', [
            'tenant' => $tenant === '' ? null : $tenant,
            'focus' => $focus,
            'tiles' => $tiles,
            'focusSetup' => $focus === null ? null : SetupStatus::of($focus),
            'focusChannels' => $focus === null ? [] : ChannelDepth::of($focus, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds),
            'focusProjectionCounts' => ProjectionOverview::counts($focusProjections),
            'focusTracing' => $focusFlowTypes['tracing'],
            'focusFlowTypes' => $focusFlowTypes['flowTypes'],
            'focusFailures' => $focus === null ? [] : array_values(array_filter($focus->snapshot['deadLetters']['groups'] ?? [], is_array(...))),
            'service' => $snapshot,
            'failing' => $failing,
            'diagnosis' => $failing ? $this->checksOf($service) : ['checks' => [], 'skipped' => false],
            'snippets' => $failing ? $this->snippets->for($service) : [],
            'servicesEntry' => $this->snippets->pulseServicesEntry($service),
            'tenantRows' => $tenantRows,
            'tenantFlowTypes' => FlowTypeOverview::of($tenantRows),
            'tenantChannels' => array_combine(
                array_map(static fn (ServiceSnapshot $row): string => (string) $row->tenant, $tenantRows),
                array_map(fn (ServiceSnapshot $row): array => array_values(array_filter(ChannelDepth::of($row, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds), static fn (ChannelDepth $depth): bool => $depth->perTenant)), $tenantRows),
            ),
            'tenantProjectionCounts' => array_combine(
                array_map(static fn (ServiceSnapshot $row): string => (string) $row->tenant, $tenantRows),
                array_map(fn (ServiceSnapshot $row): array => ProjectionOverview::counts(ProjectionOverview::of($row, $this->settings->projectionStalledAfterSeconds)), $tenantRows),
            ),
            'channels' => ChannelDepth::of($snapshot, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds),
            'projectionCounts' => ProjectionOverview::counts($projections),
            'tracing' => $flowTypes['tracing'],
            'flowTypes' => $flowTypes['flowTypes'],
            'deadLetterEnabled' => $snapshot->snapshot['deadLetter']['enabled'] ?? null,
            'waiting' => Snapshots::needsAutoRefresh([$snapshot, ...$tenantRows]),
        ]);
    }

    /**
     * @param list<ServiceSnapshot> $tenantRows
     */
    private function scopeOf(array $tenantRows, string $tenant): ?ServiceSnapshot
    {
        foreach ($tenantRows as $row) {
            if ($row->tenant === $tenant) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    private function snapshotOf(array $snapshots, string $service): ?ServiceSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service === $service) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @return array{checks: list<DoctorCheck>, skipped: bool}
     */
    private function checksOf(string $service): array
    {
        try {
            return $this->doctor->diagnoseService($service);
        } catch (Throwable) {
            return ['checks' => [], 'skipped' => true];
        }
    }
}
