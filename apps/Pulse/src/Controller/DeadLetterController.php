<?php

declare(strict_types=1);

namespace App\Controller;

use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeadLetterController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    #[Route('/dead-letters', name: 'dead_letters', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = DeadLetterFilter::fromInput($request->query);
        $snapshots = $this->snapshots->waitForReplies($this->snapshots->viewAndRequestStale());

        return $this->render('dead_letters/list.html.twig', [
            'filter' => $filter,
            'services' => DeadLetterOverview::of($snapshots, $filter),
            'serviceNames' => array_map(static fn ($snapshot) => $snapshot->service, $snapshots),
            'waiting' => Snapshots::anyWaiting($snapshots),
        ]);
    }

    #[Route('/dead-letters/{service}/{messageId}', name: 'dead_letter', methods: ['GET'], requirements: ['service' => '[^/]+', 'messageId' => '[^/]+'])]
    public function detail(string $service, string $messageId): Response
    {
        $entry = DeadLetterOverview::entry($this->snapshots->all(), $service, $messageId);
        if ($entry === null) {
            throw $this->createNotFoundException(sprintf('Dead letter "%s" of service "%s" is not in the latest 100 of its snapshot. It may have been replayed or deleted, or it is older than the snapshot lists; go back to the dead letters list.', $messageId, $service));
        }

        return $this->render('dead_letters/detail.html.twig', ['service' => $service, 'entry' => $entry]);
    }
}
