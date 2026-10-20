<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServicesController
{
    #[Route('/', name: 'services')]
    public function __invoke(): Response
    {
        return new Response('ok');
    }
}
