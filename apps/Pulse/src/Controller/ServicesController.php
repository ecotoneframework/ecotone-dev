<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServicesController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/', name: 'services', methods: ['GET'])]
    public function services(): Response
    {
        $services = $this->snapshots->viewAndRequestStale();
        $versions = Snapshots::ecotoneVersions($services);
        $newest = $versions === [] ? null : $versions[array_key_last($versions)];
        $ages = array_filter(array_map(static fn (ServiceSnapshot $service) => $service->ageSeconds, $services), static fn (?int $age) => $age !== null);

        return $this->render('services/index.html.twig', [
            'services' => $services,
            'versions' => $versions,
            'newest' => $newest,
            'summary' => [
                'connected' => count(array_filter($services, static fn (ServiceSnapshot $service) => $service->status === ServiceStatus::Connected)),
                'unreachable' => count(array_filter($services, static fn (ServiceSnapshot $service) => in_array($service->status->value, ['unreachable', 'channel_missing'], true))),
                'deadLetters' => DeadLetterOverview::total($services),
                'drift' => count(array_filter($services, static fn (ServiceSnapshot $service) => $newest !== null && $service->ecotoneVersion() !== null && $service->ecotoneVersion() !== $newest)),
            ],
            'updatedAgo' => $ages === [] ? null : min($ages),
            'waiting' => Snapshots::needsAutoRefresh($services),
        ]);
    }

    #[Route('/refresh', name: 'services_refresh', methods: ['POST'])]
    public function refresh(): Response
    {
        $this->snapshots->refresh();

        return new RedirectResponse($this->generateUrl('services'));
    }
}
