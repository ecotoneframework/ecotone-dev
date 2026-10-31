<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Messaging\Support\InvalidArgumentException;

use function sprintf;
use function strlen;
use function trim;

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
final class DistributedIdempotencyKeyGuard
{
    private const MAXIMUM_LENGTH = 255;

    public function refuseInvalidIdempotencyKey(
        #[Header(DistributedBusHeader::DISTRIBUTED_IDEMPOTENCY_KEY)]
        ?string $idempotencyKey,
    ): void {
        if ($idempotencyKey === null) {
            return;
        }

        if (trim($idempotencyKey) === '') {
            throw InvalidArgumentException::create('The Distributed Bus was given a blank idempotencyKey. The target deduplicates on this key in place of the message id, per sending service, routing key and consuming endpoint, so a blank one would make every send from this service to that routing key one request, handled once. Pass a key that names this business request, such as the order id, or omit idempotencyKey to have each send handled on its own.');
        }

        if (strlen($idempotencyKey) > self::MAXIMUM_LENGTH) {
            throw InvalidArgumentException::create(sprintf('The Distributed Bus was given an idempotencyKey of %d characters. The target stores the key in its deduplication table, which holds at most %d, so a longer one would fail every delivery of the message. Pass a key of at most %d characters that names this business request, such as the order id, or a hash of a longer one.', strlen($idempotencyKey), self::MAXIMUM_LENGTH, self::MAXIMUM_LENGTH));
        }
    }
}
