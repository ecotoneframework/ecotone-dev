<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class TracingConfiguration
{
    /**
     * @param string[] $spanRecorderReferenceNames
     */
    private function __construct(
        public readonly array $spanRecorderReferenceNames,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self([]);
    }

    public function withSpanRecorder(string $referenceName): self
    {
        return new self(...[...get_object_vars($this), 'spanRecorderReferenceNames' => [...$this->spanRecorderReferenceNames, $referenceName]]);
    }
}
