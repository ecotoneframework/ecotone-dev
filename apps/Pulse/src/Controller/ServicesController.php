<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterOverview;
use App\Settings\PulseSettings;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServicesController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly PulseSettings $settings)
    {
    }

    #[Route('/', name: 'services', methods: ['GET'])]
    public function services(): Response
    {
        $services = $this->snapshots->viewAndRequestStale();
        $versions = Snapshots::ecotoneVersions($services);
        $newest = $versions === [] ? null : $versions[array_key_last($versions)];
        $ages = array_filter(array_map(static fn (ServiceSnapshot $service) => $service->ageSeconds, $services), static fn (?int $age) => $age !== null);

        $tenantRows = [];
        $deadLetterTotals = [];
        foreach ($this->snapshots->scopes() as $row) {
            if ($row->tenant !== null) {
                $tenantRows[$row->service][] = $row;
                $deadLetterTotals[$row->service] = ($deadLetterTotals[$row->service] ?? 0) + (int) $row->failureCount();
            }
        }

        return $this->render('services/index.html.twig', [
            'services' => $services,
            'tenantRows' => $tenantRows,
            'deadLetterTotals' => $deadLetterTotals,
            'versions' => $versions,
            'newest' => $newest,
            'summary' => [
                'connected' => count(array_filter($services, static fn (ServiceSnapshot $service) => $service->status === ServiceStatus::Connected)),
                'unreachable' => count(array_filter($services, static fn (ServiceSnapshot $service) => in_array($service->status->value, ['unreachable', 'channel_missing'], true))),
                'deadLetters' => DeadLetterOverview::failures($this->snapshots->scopes()),
                'drift' => count(array_filter($services, static fn (ServiceSnapshot $service) => $newest !== null && $service->ecotoneVersion() !== null && $service->ecotoneVersion() !== $newest)),
            ],
            'channelsOver' => array_combine(
                array_map(static fn (ServiceSnapshot $service) => $service->service, $services),
                array_map(fn (ServiceSnapshot $service) => ChannelDepth::overThreshold(ChannelDepth::of($service, $this->settings->queueThresholds)) + array_sum(array_map(fn (ServiceSnapshot $row): int => ChannelDepth::overThreshold(array_filter(ChannelDepth::of($row, $this->settings->queueThresholds), static fn (ChannelDepth $depth): bool => $depth->perTenant)), $tenantRows[$service->service] ?? [])), $services),
            ),
            'updatedAgo' => $ages === [] ? null : min($ages),
            'waiting' => Snapshots::needsAutoRefresh($this->snapshots->scopes()),
        ]);
    }

    #[Route('/refresh', name: 'services_refresh', methods: ['POST'])]
    public function refresh(): Response
    {
        $this->snapshots->refresh();

        return new RedirectResponse($this->generateUrl('services'));
    }
}
