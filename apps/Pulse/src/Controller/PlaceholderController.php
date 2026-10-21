<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceholderController extends AbstractController
{
    #[Route('/dead-letters', name: 'dead_letters', methods: ['GET'])]
    public function deadLetters(): Response
    {
        return $this->placeholder('Dead letters');
    }

    private function placeholder(string $title): Response
    {
        return $this->render('placeholder.html.twig', ['title' => $title]);
    }
}
