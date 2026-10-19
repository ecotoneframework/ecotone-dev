<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Attribute;
use Ecotone\Api\Attribute\InternalHandler;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class OnFlowStepCompleted extends InternalHandler
{
    public const CHANNEL = 'ecotone.tracing.flowStepCompleted';

    public function __construct()
    {
        parent::__construct(self::CHANNEL);
    }

    public function asynchronousExecutionRefusal(): string
    {
        return 'cannot run #[Asynchronous]: a flow notification is delivered from memory once the work committed, so it is never queued. Remove #[Asynchronous], and send a command to an #[Asynchronous] handler from this method for the work that should run in the background.';
    }
}
