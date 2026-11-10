<?php

declare(strict_types=1);

namespace App\Ui;

final readonly class Concept
{
    public function __construct(
        public string $term,
        public string $name,
        public string $question,
        public string $what,
        public string $why,
        public string $todo,
        public ?string $docs = null,
    ) {
    }
}
