<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Symfony;

use Monorepo\SwooleApp\Common\Probe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProbeController
{
    public function __construct(private Probe $probe)
    {
    }

    public function __invoke(Request $request, string $action): JsonResponse
    {
        return new JsonResponse($this->probe->handle($action, json_decode($request->getContent() ?: '[]', true) + $request->query->all()));
    }
}
