<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing\Pulse;

use Ecotone\Messaging\Support\InvalidArgumentException;
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
final class PulseTracesNotConfigured
{
    public function __construct(private string $serviceName)
    {
    }

    public function refuse(?string $sourceService, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);

        throw InvalidArgumentException::create("Service {$this->serviceName} was asked by Pulse for traces, but flow tracking is not configured there, so it recorded none. Enable it with FlowTrackingConfiguration::createWithDefaults() in a #[ServiceContext] of {$this->serviceName}, or stop sending trace queries to this service.");
    }
}
