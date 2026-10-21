<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connect\ConnectSnippets;
use App\Doctor\Doctor;
use App\Doctor\DoctorCheck;
use App\Projection\ProjectionOverview;
use App\Settings\PulseSettings;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Trace\FlowTypeOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    #[Route('/services/{service}', name: 'service_hub', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function hub(string $service): Response
    {
        $snapshot = $this->snapshotOf($this->snapshots->viewAndRequestStale(), $service);
        if ($snapshot === null) {
            throw $this->createNotFoundException(sprintf('Service "%s" is not configured in Pulse. Add it to PULSE_SERVICES, for example PULSE_SERVICES=%s.', $service, implode(',', [...array_keys($this->settings->routes), $service])));
        }

        $failing = in_array($snapshot->status->value, ['unreachable', 'channel_missing'], true);
        $flowTypes = FlowTypeOverview::of([$snapshot])[0];
        $tenantRows = array_values(array_filter($this->snapshots->scopes(), static fn (ServiceSnapshot $row): bool => $row->service === $service && $row->tenant !== null));
        $projections = ProjectionOverview::of($snapshot, $this->settings->projectionStalledAfterSeconds);

        return $this->render('services/hub.html.twig', [
            'service' => $snapshot,
            'failing' => $failing,
            'diagnosis' => $failing ? $this->checksOf($service) : ['checks' => [], 'skipped' => false],
            'snippets' => $failing ? $this->snippets->for($service) : [],
            'servicesEntry' => $this->snippets->pulseServicesEntry($service),
            'tenantRows' => $tenantRows,
            'tenantFlowTypes' => FlowTypeOverview::of($tenantRows),
            'tenantChannels' => array_combine(
                array_map(static fn (ServiceSnapshot $row): string => (string) $row->tenant, $tenantRows),
                array_map(fn (ServiceSnapshot $row): array => array_values(array_filter(ChannelDepth::of($row, $this->settings->queueThresholds), static fn (ChannelDepth $depth): bool => $depth->perTenant)), $tenantRows),
            ),
            'tenantProjectionCounts' => array_combine(
                array_map(static fn (ServiceSnapshot $row): string => (string) $row->tenant, $tenantRows),
                array_map(fn (ServiceSnapshot $row): array => ProjectionOverview::counts(ProjectionOverview::of($row, $this->settings->projectionStalledAfterSeconds)), $tenantRows),
            ),
            'channels' => ChannelDepth::of($snapshot, $this->settings->queueThresholds),
            'projectionCounts' => ProjectionOverview::counts($projections),
            'tracing' => $flowTypes['tracing'],
            'flowTypes' => $flowTypes['flowTypes'],
            'deadLetterEnabled' => $snapshot->snapshot['deadLetter']['enabled'] ?? null,
            'waiting' => Snapshots::needsAutoRefresh([$snapshot, ...$tenantRows]),
        ]);
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
