<?php

declare(strict_types=1);

namespace App\Controller;

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

        return $this->render('services/index.html.twig', [
            'services' => $services,
            'versions' => Snapshots::ecotoneVersions($services),
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
