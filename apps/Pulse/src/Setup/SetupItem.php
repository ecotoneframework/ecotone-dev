<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupItem
{
    public function __construct(
        public string $kind,
        public string $key,
        public ?string $connection,
        public ?bool $initialized,
        public ?string $error,
        public ?string $fix,
    ) {
    }

    public function id(): string
    {
        return $this->kind . ':' . $this->key;
    }

    public function isUnknown(): bool
    {
        return $this->initialized === null;
    }

    public function isMissing(): bool
    {
        return $this->initialized === false;
    }
}
