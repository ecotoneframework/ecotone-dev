<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\MissingReferenceChain;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class ChainedHandler
{
    #[CommandHandler('missing_reference.chain')]
    public function handle(string $payload, RequiresMissingClass $collaborator): string
    {
        return $payload;
    }
}
