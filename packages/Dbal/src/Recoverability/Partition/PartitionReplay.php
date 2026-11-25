<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;

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
 */
final class PartitionReplay
{
    public function __construct(
        private HeldPartitions $heldPartitions,
        private DeadLetterStore $deadLetterStore,
        private DeadLetterHandler $deadLetterHandler,
        private MessagingEntrypointService $messagingEntrypoint,
    ) {
    }

    public function isReplaying(DeadLetterPartition $partition): bool
    {
        return $this->heldPartitions->releasedOf($partition) !== [];
    }

    public function continueWith(DeadLetterPartition $partition): bool
    {
        foreach ($this->heldPartitions->releasedOf($partition) as $releasedDeadLetterId) {
            if ($this->deadLetterStore->find($releasedDeadLetterId) !== null) {
                $this->deadLetterHandler->replay($releasedDeadLetterId, $this->messagingEntrypoint);

                return true;
            }
        }

        $nextDeadLetterId = $this->heldPartitions->releaseOldestWaitingOf($partition);
        if ($nextDeadLetterId === null) {
            return false;
        }
        $this->deadLetterHandler->replay($nextDeadLetterId, $this->messagingEntrypoint);

        return true;
    }
}
