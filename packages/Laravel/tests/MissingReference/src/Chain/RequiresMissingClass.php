<?php

declare(strict_types=1);

namespace App\MissingReference\Laravel\Chain;

/**
 * Instantiable on the surface — but its constructor requires a class that
 * does not exist, so no container can provide it at dispatch time.
 *
 * licence Apache-2.0
 */
final class RequiresMissingClass
{
    public function __construct(NonExistingCollaborator $collaborator)
    {
    }
}
