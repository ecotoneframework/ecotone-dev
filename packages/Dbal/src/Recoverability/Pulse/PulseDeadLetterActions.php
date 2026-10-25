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

    /**
     * @param array<string, mixed> $headers
     */
    public function replay(string $payload, ?string $sourceService, array $headers, PulseSnapshot $snapshot, MessagingEntrypointService $messagingEntrypoint): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $snapshot->tenantOfScopedRequest($headers);
        $selection = PulseDeadLetterSelection::forReplay($payload);

        $this->deadLetterHandler->assertTargetChannelExists($selection->targetChannel);
        $affected = 0;
        foreach ($this->existing($selection) as $messageId) {
            $this->deadLetterHandler->replay($messageId, $messagingEntrypoint, $selection->targetChannel);
            $affected++;
        }

        return self::replyWithAffected($snapshot->snapshotJson($sourceService, $headers), $affected);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function delete(string $payload, ?string $sourceService, array $headers, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $snapshot->tenantOfScopedRequest($headers);
        $selection = PulseDeadLetterSelection::forDelete($payload);

        $affected = 0;
        foreach ($this->existing($selection) as $messageId) {
            $this->deadLetterHandler->delete($messageId);
            $affected++;
        }

        return self::replyWithAffected($snapshot->snapshotJson($sourceService, $headers), $affected);
    }

    private static function replyWithAffected(string $snapshotJson, int $affected): string
    {
        return json_encode([...json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR), 'deadLetterAction' => ['affected' => $affected]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string>
     */
    private function existing(PulseDeadLetterSelection $selection): iterable
    {
        foreach ($selection->messageIds($this->store) as $messageId) {
            if ($selection->selectsByFilter() && $this->store->find($messageId) === null) {
                continue;
            }

            yield $messageId;
        }
    }
}
