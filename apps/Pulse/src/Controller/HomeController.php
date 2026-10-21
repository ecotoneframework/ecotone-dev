<?php

declare(strict_types=1);

namespace App\Controller;

use App\Home\ProblemBoard;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly Snapshots $snapshots, private readonly ProblemBoard $board)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $scopes = $this->snapshots->viewScopesAndRequestStale();
        $tiles = $this->board->tiles($scopes);
        $ages = array_filter(array_map(static fn (ServiceSnapshot $scope): ?int => $scope->ageSeconds, $scopes), static fn (?int $age): bool => $age !== null);

        return $this->render('home/index.html.twig', [
            'tiles' => $tiles,
            'summary' => ProblemBoard::summary($tiles),
            'updatedAgo' => $ages === [] ? null : min($ages),
            'waiting' => Snapshots::needsAutoRefresh($scopes),
        ]);
    }
}
