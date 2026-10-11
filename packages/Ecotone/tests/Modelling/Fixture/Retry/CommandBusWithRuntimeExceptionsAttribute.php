<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Gateway\CommandBus;
use RuntimeException;

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
#[InstantRetry(retryTimes: 3, exceptions: [RuntimeException::class])]
interface CommandBusWithRuntimeExceptionsAttribute extends CommandBus
{
}
