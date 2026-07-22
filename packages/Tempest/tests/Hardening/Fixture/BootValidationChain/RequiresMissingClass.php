<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\BootValidationChain;

/**
 * Instantiable on the surface — but its constructor requires a class that
 * does not exist. Boot validation must catch this transitively, without
 * constructing anything.
 *
 * licence Apache-2.0
 */
final class RequiresMissingClass
{
    public function __construct(NonExistingCollaborator $collaborator)
    {
    }
}
