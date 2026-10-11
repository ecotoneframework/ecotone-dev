<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;
use Ecotone\Api\Interceptor\Precedence;

#[Attribute(Attribute::TARGET_METHOD)]
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
final class ChannelInterceptor
{
    public function __construct(
        private string $channelName,
        private bool   $changeHeaders = false,
        private int    $precedence = Precedence::DEFAULT_PRECEDENCE,
    ) {
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function isChangeHeaders(): bool
    {
        return $this->changeHeaders;
    }

    public function getPrecedence(): int
    {
        return $this->precedence;
    }
}
