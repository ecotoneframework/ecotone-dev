<?php

namespace Test\Ecotone\Messaging\Fixture\FetchAggregate;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Fetch;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class ComplexService
{
    private array $results = [];

    #[CommandHandler]
    public function handleComplexCommand(
        ComplexCommand $command,
        #[Fetch("reference('identifierMapper').map(payload.email)")] User $user
    ): void {
        $this->results[] = [
            'command' => $command,
            'user' => $user,
            'userName' => $user?->getName(),
        ];
    }

    #[CommandHandler('handleWithArrayIdentifiers')]
    public function handleWithArrayIdentifiers(
        ComplexCommand $command,
        #[Fetch("{'userId': reference('identifierMapper').map(payload.email)}")] User $user
    ): void {
        $this->results[] = [
            'command' => $command,
            'user' => $user,
            'userName' => $user?->getName(),
        ];
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getLastResult(): ?array
    {
        return end($this->results) ?: null;
    }
}
