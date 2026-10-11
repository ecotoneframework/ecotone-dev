<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Closure;
use Ecotone\Messaging\Attribute\WithExpression;

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
#[Attribute(Attribute::TARGET_METHOD)]
final class WithTenantResolver implements WithExpression
{
    public function __construct(public string|Closure $expression)
    {
    }

    public function getExpression(): string|Closure
    {
        return $this->expression;
    }
}
