<?php

declare(strict_types=1);

namespace Ecotone\Api\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

/**
 * licence Apache-2.0
 */
final class DropDatabaseCallsOutsideAnyTrace implements SamplerInterface
{
    /**
     * @param list<string> $spanNamePrefixes
     */
    private function __construct(
        public readonly array $spanNamePrefixes,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self(['PDO', 'sql ']);
    }

    /**
     * @param list<string> $spanNamePrefixes
     */
    public function withSpanNamePrefixes(array $spanNamePrefixes): self
    {
        return new self(...[...get_object_vars($this), 'spanNamePrefixes' => $spanNamePrefixes]);
    }

    public function shouldSample(ContextInterface $parentContext, string $traceId, string $spanName, int $spanKind, AttributesInterface $attributes, array $links): SamplingResult
    {
        $parent = Span::fromContext($parentContext)->getContext();
        if ($parent->isValid()) {
            return new SamplingResult($parent->isSampled() ? SamplingResult::RECORD_AND_SAMPLE : SamplingResult::DROP, [], $parent->getTraceState());
        }

        foreach ($this->spanNamePrefixes as $spanNamePrefix) {
            if (str_starts_with($spanName, $spanNamePrefix)) {
                return new SamplingResult(SamplingResult::DROP);
            }
        }

        return new SamplingResult(SamplingResult::RECORD_AND_SAMPLE);
    }

    public function getDescription(): string
    {
        return 'DropDatabaseCallsOutsideAnyTrace';
    }
}
