<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Tracing\Span;
use Ecotone\Tracing\ActiveSpan;

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
final class RecordedSpans
{
    /** @var Span[] */
    public array $buffer = [];
    public bool $scopeActive = false;
    public bool $scopeExcludedFromTracking = false;
    public ?ActiveSpan $wrappingSpan = null;
    /** @var array<string, true> */
    public array $storedSpanIds = [];
    public bool $projectionBatchActive = false;
    public bool $unitOfWorkActive = false;
    /** @var Span[] */
    public array $committedSpans = [];
    public ?string $consumedMessageSpanId = null;
    /** @var array{wrappingSpanId: string, spans: Span[], append: Closure(Span[], ?string): void}|null */
    public ?array $failedAttempt = null;
}
