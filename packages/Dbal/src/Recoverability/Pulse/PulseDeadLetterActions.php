<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseDeadLetterActions
{
    public function __construct(
        private DeadLetterStore $store,
        private DeadLetterHandler $deadLetterHandler,
    ) {
    }

    public function replay(string $payload, ?string $sourceService, PulseSnapshot $snapshot, MessagingEntrypointService $messagingEntrypoint): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $selection = PulseDeadLetterSelection::forReplay($payload);

        $this->deadLetterHandler->replay($selection->messageIds($this->store), $messagingEntrypoint, $selection->targetChannel);

        return $snapshot->snapshotJson($sourceService);
    }

    public function delete(string $payload, ?string $sourceService, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $selection = PulseDeadLetterSelection::forDelete($payload);

        $this->deadLetterHandler->delete($selection->messageIds($this->store));

        return $snapshot->snapshotJson($sourceService);
    }
}
