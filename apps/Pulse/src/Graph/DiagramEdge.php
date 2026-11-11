<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class DiagramEdge
{
    public const SOLID = 'solid';
    public const DASHED = 'dashed';
    public const THICK = 'thick';

    public function __construct(
        public string $from,
        public string $to,
        public string $arrow,
        public string $label = '',
        public bool $highlighted = false,
    ) {
    }

    public function key(): string
    {
        return implode('|', [$this->from, $this->arrow, $this->label, $this->to]);
    }

    public function orHighlighted(bool $highlighted): self
    {
        return new self($this->from, $this->to, $this->arrow, $this->label, $this->highlighted || $highlighted);
    }
}
