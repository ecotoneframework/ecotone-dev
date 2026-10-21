<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function healthz(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
