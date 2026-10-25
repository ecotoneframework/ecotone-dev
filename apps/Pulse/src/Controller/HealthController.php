<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\Heartbeats;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(private Heartbeats $heartbeats)
    {
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function healthz(): Response
    {
        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    #[Route('/readyz', name: 'readyz', methods: ['GET'])]
    public function readyz(): JsonResponse
    {
        $checks = $this->heartbeats->readiness();
        $ready = array_reduce($checks, static fn (bool $ready, $check) => $ready && $check->ok, true);

        return new JsonResponse([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => array_map(static fn ($check) => ['name' => $check->name, 'ok' => $check->ok, 'message' => $check->message], $checks),
        ], $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
