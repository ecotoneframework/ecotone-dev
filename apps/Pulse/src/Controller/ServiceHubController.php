<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connect\ConnectSnippets;
use App\Consumer\Consumer;
use App\Consumer\ConsumerSettings;
use App\Doctor\Doctor;
use App\Doctor\DoctorCheck;
use App\Home\ProblemBoard;
use App\Settings\PulseSettings;
use App\Setup\SetupStatus;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Snapshot\Snapshots;
use App\Ui\ServiceOverview;
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
        private readonly ServiceOverview $overview,
    ) {
    }

    #[Route('/services/{service}', name: 'service_hub', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function hub(string $service, Request $request): Response
    {
        $context = $this->contextOf($service, $request);
        $snapshot = $context['service'];
        $focus = $context['focus'];
        $failing = in_array($snapshot->status, [ServiceStatus::Unreachable, ServiceStatus::ChannelMissing], true);
        $tiles = $this->board->tiles($focus === null ? $context['tenantRows'] : [$focus], $this->snapshots->scopes());
        $cards = $this->overview->problemCards($tiles);
        $summaryTiles = $this->overview->tiles($snapshot, $focus, $context['channels'], $context['perTenantChannels']);

        return $this->render('services/hub.html.twig', [
            ...$context,
            'verdict' => $this->overview->verdict($snapshot, $focus, $cards, $summaryTiles, $context['channels'], $context['tenantRows']),
            'problems' => $cards,
            'problemsShown' => ServiceOverview::PROBLEMS_SHOWN,
            'summaryTiles' => $summaryTiles,
            'channelsSummary' => $this->overview->channelsSummary($context['channels']),
            'channelsStatus' => ChannelDepth::statusOf($context['channels'])->value,
            'failing' => $failing,
            'diagnosis' => $failing ? $this->checksOf($service) : ['checks' => [], 'skipped' => false],
            'snippets' => $failing ? $this->snippets->for($service) : [],
            'servicesEntry' => $this->snippets->pulseServicesEntry($service),
        ]);
    }

    #[Route('/services/{service}/channels', name: 'service_channels', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function channels(string $service, Request $request): Response
    {
        $context = $this->contextOf($service, $request);
        $consumers = $context['focus'] === null ? [] : Consumer::allOf($context['focus']);

        return $this->render('services/channels.html.twig', [
            ...$context,
            'shownChannels' => ChannelDepth::byUrgency($context['channels']),
            'unmeasured' => $this->overview->unmeasured($context['channels'], $context['perTenantChannels']),
            'channelsSummary' => $this->overview->channelsSummary($context['channels']),
            'channelsStatus' => ChannelDepth::statusOf($context['channels'])->value,
            'pollingConsumers' => array_values(array_filter($consumers, static fn (Consumer $consumer): bool => $consumer->kind === 'polling')),
            'declaredConsumers' => array_count_values(array_map(static fn (Consumer $consumer): string => $consumer->name, array_filter($consumers, static fn (Consumer $consumer): bool => $consumer->kind === 'polling'))),
            'inboundConsumers' => array_values(array_filter($consumers, static fn (Consumer $consumer): bool => $consumer->kind !== 'polling')),
            'consumerSettings' => ConsumerSettings::of($consumers),
            'anyConsumer' => $consumers !== [],
            'tenantChannels' => array_combine(
                array_map(static fn (ServiceSnapshot $row): string => (string) $row->tenant, $context['tenantRows']),
                array_map(fn (ServiceSnapshot $row): array => ChannelDepth::byUrgency(array_values(array_filter($this->depthsOf($row), static fn (ChannelDepth $depth): bool => $depth->perTenant))), $context['tenantRows']),
            ),
        ]);
    }

    #[Route('/services/{service}/configuration', name: 'service_configuration', methods: ['GET'], requirements: ['service' => '[^/]+'])]
    public function configuration(string $service, Request $request): Response
    {
        $context = $this->contextOf($service, $request);

        return $this->render('services/configuration.html.twig', [
            ...$context,
            'setup' => $context['focus'] === null ? null : SetupStatus::of($context['focus']),
        ]);
    }

    /**
     * @return array{service: ServiceSnapshot, tenant: ?string, focus: ?ServiceSnapshot, tenantRows: list<ServiceSnapshot>, channels: list<ChannelDepth>, perTenantChannels: list<string>, tabs: array<string, string>, failureCount: ?int, waiting: bool}
     */
    private function contextOf(string $service, Request $request): array
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

        $tenantRows = array_values(array_filter($this->snapshots->scopes(), static fn (ServiceSnapshot $row): bool => $row->service === $service && $row->tenant !== null));
        $focus = $tenant !== '' ? $this->scopeOf($tenantRows, $tenant) : ($snapshot->isMultiTenant() ? null : $snapshot);
        $perTenantChannels = [];
        foreach ($tenantRows as $row) {
            foreach ($this->depthsOf($row) as $depth) {
                if ($depth->perTenant) {
                    $perTenantChannels[] = $depth->name;
                }
            }
        }

        return [
            'service' => $snapshot,
            'tenant' => $tenant === '' ? null : $tenant,
            'focus' => $focus,
            'tenantRows' => $tenantRows,
            'channels' => $this->depthsOf($focus ?? $snapshot),
            'perTenantChannels' => array_values(array_unique($perTenantChannels)),
            'tabs' => $this->overview->tabRoutes($snapshot, $tenant === '' ? null : $tenant),
            'failureCount' => $focus?->deadLetterTotal(),
            'waiting' => Snapshots::needsAutoRefresh([$snapshot, ...$tenantRows]),
        ];
    }

    /**
     * @return list<ChannelDepth>
     */
    private function depthsOf(ServiceSnapshot $snapshot): array
    {
        return ChannelDepth::of($snapshot, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds);
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
