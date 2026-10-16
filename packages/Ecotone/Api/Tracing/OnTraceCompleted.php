<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

use Attribute;
use Ecotone\Api\Attribute\InternalHandler;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class OnTraceCompleted extends InternalHandler
{
    public const CHANNEL = 'ecotone.tracing.traceCompleted';

    public function __construct()
    {
        parent::__construct(self::CHANNEL);
    }
}
