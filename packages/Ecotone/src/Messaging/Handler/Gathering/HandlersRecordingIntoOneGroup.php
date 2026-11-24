<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\Gathered;

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
final class HandlersRecordingIntoOneGroup
{
    /** @var array<class-string, array{string, string, string, string}> */
    public const LOST_WHEN_HANDLED_AT_ONCE = [
        Gathered::class => ['Gathered', "parts finishing together would be recorded in their group at the same time, all but one would lose the group's optimistic write on every attempt until the instant retries run out, and a group missing those parts is never gathered.", 'keep the handlers doing the work of the parts on another channel', 'gathered'],
        Collected::class => ['Collected', "messages arriving together would be recorded in their batch at the same time, all but one would lose the batch's optimistic write on every attempt until the instant retries run out, and those messages are missing from the batch.", 'keep handlers that should run at once on another channel', 'collected'],
    ];
}
