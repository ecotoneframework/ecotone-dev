<?php

namespace General\RoundRobinWithSingleConsumer\src;

final readonly class ProcessImage
{
    public function __construct(
        public string $imageId,
        public string $content
    ) {}
}