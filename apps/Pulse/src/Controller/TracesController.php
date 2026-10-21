<?php

declare(strict_types=1);

namespace App\Controller;

use App\Snapshot\Snapshots;
use App\Trace\FlowTypeOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TracesController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/traces', name: 'traces', methods: ['GET'])]
    public function index(): Response
    {
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());

        return $this->render('traces/index.html.twig', [
            'services' => FlowTypeOverview::of($snapshots),
            'waiting' => Snapshots::needsAutoRefresh($snapshots),
        ]);
    }
}
