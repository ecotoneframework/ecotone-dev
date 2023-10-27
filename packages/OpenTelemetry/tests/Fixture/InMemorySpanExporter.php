<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture;

use ArrayObject;
use OpenTelemetry\SDK\Trace\Behavior\SpanExporterTrait;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class InMemorySpanExporter implements SpanExporterInterface
{
    use SpanExporterTrait;

    private ArrayObject $storage;

    public function __construct()
    {
        $this->storage = new ArrayObject();
    }

    protected function doExport(iterable $spans): bool
    {
        foreach ($spans as $span) {
            $this->storage[] = $span;
        }

        return true;
    }

    public function getSpans(): array
    {
        return (array) $this->storage;
    }

    public function clean(): void
    {
        $this->storage = new ArrayObject();
    }
}
