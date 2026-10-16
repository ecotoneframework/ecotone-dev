<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

use Ecotone\Api\Scheduling\Duration;

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
        public readonly bool $storedTraces,
        public readonly bool $storedTracesInMemory,
        public readonly Duration $storedTracesRetention,
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self([], false, false, Duration::minutes(60 * 24 * 7));
    }

    public function withSpanRecorder(string $referenceName): self
    {
        return new self(...[...get_object_vars($this), 'spanRecorderReferenceNames' => [...$this->spanRecorderReferenceNames, $referenceName]]);
    }

    public function withStoredTraces(bool $inMemory = false, ?Duration $retention = null): self
    {
        return new self(...[...get_object_vars($this), 'storedTraces' => true, 'storedTracesInMemory' => $inMemory, 'storedTracesRetention' => $retention ?? $this->storedTracesRetention]);
    }
}
