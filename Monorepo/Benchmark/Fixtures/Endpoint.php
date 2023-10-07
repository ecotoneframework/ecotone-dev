<?php

namespace Monorepo\Benchmark\Fixtures;

#[\Attribute]
class Endpoint
{
    public function __construct(public string $endpointId, public ?Endpoint $endpoint = null)
    {
    }
}