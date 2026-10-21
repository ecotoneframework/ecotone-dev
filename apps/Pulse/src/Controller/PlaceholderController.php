<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceholderController extends AbstractController
{
    #[Route('/flow-graph', name: 'flow_graph', methods: ['GET'])]
    public function flowGraph(): Response
    {
        return $this->placeholder('Flow graph');
    }

    #[Route('/topology', name: 'topology', methods: ['GET'])]
    public function topology(): Response
    {
        return $this->placeholder('Topology');
    }

    #[Route('/catalogue', name: 'catalogue', methods: ['GET'])]
    public function catalogue(): Response
    {
        return $this->placeholder('Catalogue');
    }

    private function placeholder(string $title): Response
    {
        return $this->render('placeholder.html.twig', ['title' => $title]);
    }
}
