<?php

declare(strict_types=1);

namespace App\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'DEMO_PULSE_URL')] private readonly string $pulseUrl,
        #[Autowire(env: 'DEMO_JAEGER_URL')] private readonly string $jaegerUrl,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'pulseUrl' => $this->pulseUrl,
            'jaegerUrl' => $this->jaegerUrl . '/search?service=ticketing',
        ]);
    }
}
