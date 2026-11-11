<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class DiagramNode
{
    public function __construct(
        public string $id,
        public ?string $group,
        public string $kind,
        public string $label,
        public ?string $note = null,
        public ?string $state = null,
        public bool $greyed = false,
        public bool $focused = false,
        public ?int $step = null,
        public ?string $href = null,
        public ?string $title = null,
        public bool $current = false,
    ) {
    }

    /**
     * @param array{href?: string, title?: string, current?: true} $link
     */
    public function linked(array $link): self
    {
        return new self($this->id, $this->group, $this->kind, $this->label, $this->note, $this->state, $this->greyed, $this->focused, $this->step, $link['href'] ?? null, $link['title'] ?? null, $link['current'] ?? false);
    }

    public function kindLabel(): string
    {
        return ucfirst(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $this->kind)));
    }

    public function family(): string
    {
        return match (true) {
            in_array($this->kind, ['command', 'event', 'query', 'channel', 'projection', 'service'], true) => $this->kind,
            in_array($this->kind, ['aggregate', 'saga'], true) => 'owner',
            $this->kind === 'distributedBus' => 'channel',
            default => 'handler',
        };
    }
}
