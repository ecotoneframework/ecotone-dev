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
        return new self(
            id: $this->id,
            group: $this->group,
            kind: $this->kind,
            label: $this->label,
            note: $this->note,
            state: $this->state,
            greyed: $this->greyed,
            focused: $this->focused,
            step: $this->step,
            href: $link['href'] ?? null,
            title: $link['title'] ?? null,
            current: $link['current'] ?? false,
        );
    }

    public function kindLabel(): string
    {
        return self::kindLabelOf($this->kind);
    }

    public function family(): string
    {
        return self::familyOf($this->kind);
    }

    public static function kindLabelOf(string $kind): string
    {
        return ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $kind))));
    }

    public static function familyOf(string $kind): string
    {
        return match (true) {
            in_array($kind, ['command', 'event', 'query', 'channel', 'projection', 'service'], true) => $kind,
            in_array($kind, ['aggregate', 'saga'], true) => 'owner',
            $kind === 'distributedBus' => 'channel',
            default => 'handler',
        };
    }
}
