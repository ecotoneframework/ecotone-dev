<?php

namespace App\MultiTenant;

final readonly class ProcessImage
{
    public function __construct(
        public string $imageId,
        public string $content
    ) {}
}