<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Handler\ReferenceSearchService;
use Illuminate\Contracts\Foundation\Application;

class LaravelReferenceSearchService implements ReferenceSearchService
{
    /**
     * @var Application
     */
    private $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function get(string $reference): object
    {
        return $this->application->get($reference);
    }

    public function has(string $referenceName): bool
    {
        return $this->application->has($referenceName);
    }
}
