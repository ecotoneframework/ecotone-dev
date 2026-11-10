<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use App\Ui\ServiceTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServicesController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly ServiceTable $table)
    {
    }

    #[Route('/services', name: 'services', methods: ['GET'])]
    public function services(): Response
    {
        $services = $this->snapshots->viewAndRequestStale();
        $scopes = $this->snapshots->scopes();
        $rows = $this->table->rows($services, $scopes);
        $ages = array_filter(array_map(static fn (ServiceSnapshot $service) => $service->ageSeconds, $services), static fn (?int $age) => $age !== null);

        return $this->render('services/index.html.twig', [
            'rows' => $rows,
            'verdict' => $this->table->verdict($rows),
            'versions' => Snapshots::ecotoneVersions($services),
            'summary' => [...ServiceTable::summary($rows), 'deadLetters' => DeadLetterOverview::failures($scopes)],
            'updatedAgo' => $ages === [] ? null : min($ages),
            'waiting' => Snapshots::needsAutoRefresh($scopes),
        ]);
    }

    #[Route('/refresh', name: 'services_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $this->snapshots->refresh();

        return new RedirectResponse($this->generateUrl($request->request->get('return') === 'home' ? 'home' : 'services'));
    }
}
